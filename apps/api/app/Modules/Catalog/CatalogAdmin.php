<?php

namespace App\Modules\Catalog;

use App\Modules\Foundation\Audit\Journal;
use App\Modules\Foundation\Audit\Subject;
use App\Modules\Foundation\Idempotency\IdempotentMutation;
use App\Modules\Foundation\Outbox\Outbox;
use App\Support\Http\ApiInput;
use App\Support\Http\ApiProblem;
use App\Support\Http\ApiResponse;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class CatalogAdmin
{
    public const FILTERS = [...ApiInput::PAGE, 'q' => 'sometimes|required|string|max:120', 'active' => 'sometimes|string|in:0,1,true,false'];

    public const ID = 'integer|min:1|max:2147483647';

    public const CODE = 'regex:/\A[A-Za-z0-9_.-]+\z/D';

    public const SLUG = 'regex:/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/D';

    /** @param array<string,mixed> $filters */
    public function page(Request $request, Resource $kind, array $filters): JsonResponse
    {
        ApiInput::fields($request, $filters);
        $q = $this->query($kind);
        if (isset($filters['active'])) {
            $active = filter_var($filters['active'], FILTER_VALIDATE_BOOL);
            $kind === Resource::Product ? $q->where('r.status', $active ? '=' : '<>', 'PUBLISHED') : $q->where('r.active', $active);
        }
        foreach (['product_id', 'attribute_id'] as $fk) {
            if (isset($filters[$fk])) {
                $q->where("r.$fk", $filters[$fk]);
            }
        }
        if (isset($filters['q'])) {
            $term = '%'.addcslashes($filters['q'], '\\%_').'%';
            $q->whereLike('r.'.$kind->searchField(), $term);
        }
        $page = $q->orderByDesc('r.id')->paginate((int) ($filters['per_page'] ?? 20));

        return ApiResponse::success($this->rows($kind, $page->getCollection()), meta: ['page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'last_page' => $page->lastPage()])->header('Cache-Control', 'private, no-store');
    }

    public function show(Resource $kind, int $id, int $status = 200): JsonResponse
    {
        $row = $this->query($kind)->where('r.id', $id)->first() ?? abort(404);

        return ApiResponse::success($this->rows($kind, collect([$row]))[0], status: $status)->header('Cache-Control', 'private, no-store');
    }

    private function query(Resource $kind): Builder
    {
        $q = DB::table($kind->value.' as r')->select('r.*');
        if (in_array($kind, [Resource::Product, Resource::Variant, Resource::Category, Resource::Brand], true)) {
            $q->whereNull('r.deleted_at');
        }
        if ($kind === Resource::ProductMedia) {
            $q->join('stored_files as f', 'f.id', '=', 'r.file_id')->addSelect('f.public_id as file_ref');
        }

        return $q;
    }

    /**
     * @param  Collection<int,\stdClass>  $rows
     * @return list<array<string,mixed>>
     */
    private function rows(Resource $kind, Collection $rows): array
    {
        $links = [];
        $parent = $kind === Resource::Product ? 'product_id' : 'variant_id';
        $relations = match ($kind) {
            Resource::Variant => ['attribute_value_ids' => ['variant_attribute_values', 'value_id']],
            Resource::Product => array_map(fn ($pair) => ['product_'.$pair[0], $pair[1]], CatalogEditor::RELATIONS),
            default => [],
        };
        foreach ($relations as $field => [$table, $fk]) {
            $links[$field] = DB::table($table)->whereIntegerInRaw($parent, $rows->pluck('id')->all())
                ->orderBy($fk)->get([$parent, $fk])->groupBy($parent)->map(fn ($values) => $values->pluck($fk)->all());
        }

        return $rows->map(function ($row) use ($kind, $links) {
            $data = Arr::only((array) $row, match ($kind) {
                Resource::Product => ['id', 'name', 'slug', 'description', 'status', 'brand_id', 'tax_class_id', 'authenticity', 'seo_title', 'seo_description'],
                Resource::Variant => ['id', 'name', 'product_id', 'sku', 'unit_id', 'rts_enabled', 'rts_price_amount', 'weight_g', 'length_mm', 'width_mm', 'height_mm', 'active'],
                Resource::ProductMedia => ['id', 'product_id', 'variant_id', 'alt', 'position', 'active'],
                Resource::AttributeValue => ['id', 'attribute_id', 'code', 'label', 'position', 'active'],
                default => ['id', 'slug', 'code', 'parent_id', 'description', 'name', 'active'],
            });
            foreach ($links as $field => $values) {
                $data[$field] = $values[$row->id] ?? [];
            }
            if ($kind === Resource::ProductMedia) {
                $data['file_id'] = $row->file_ref;
            }

            return $data;
        })->values()->all();
    }

    /** @param array<string,mixed> $data */
    public function save(Request $request, Resource $kind, array $data, ?int $id = null): JsonResponse
    {
        ApiInput::fields($request, $data);
        $actor = $request->user();
        $authorize = function () use ($actor, $data, $id, $kind): string {
            $user = $actor->fresh();
            abort_unless($user?->can($kind->permission($id === null ? 'create' : 'update')), 403);
            if (isset($data['status']) && ($data['status'] !== 'DRAFT' || $id !== null)) {
                abort_unless($user->can($kind->permission($data['status'] === 'ARCHIVED' ? 'archive' : 'publish')), 403);
            }
            if (isset($data['active']) && ! $data['active']) {
                abort_unless($user->can($kind->permission('archive')), 403);
            }

            return 'user:'.$actor->id;
        };
        try {
            return app(IdempotentMutation::class)->run($request, $authorize, function () use ($authorize, $kind, $data, $id, $actor) {
                // A taxonomy change can affect many products. Serialize catalog edits, never public reads.
                DB::select("SELECT pg_advisory_xact_lock(hashtextextended('catalog:edit', 0))");
                $authorize();
                $resource = $this->query($kind)->where('r.id', $id);
                $before = $id === null ? null : ((clone $resource)->first() ?? abort(404));
                $product = $kind === Resource::Product ? $id : ($before->product_id ?? $data['product_id'] ?? null);
                if ($product !== null && ! DB::table('products')->where('id', $product)->whereNull('deleted_at')->lockForUpdate()->first(['id'])) {
                    throw ValidationException::withMessages(['product_id' => 'Select an existing product.']);
                }
                // Checkout and stock reservations lock the product before its variants.
                if ($id !== null && $kind === Resource::Variant) {
                    $before = $resource->lockForUpdate()->first() ?? abort(404);
                }
                $key = app(CatalogEditor::class)->save($kind, $data, $before);
                $event = $kind->name.'Changed';
                $subject = Subject::from(match ($kind) {
                    Resource::AttributeValue => 'attribute_value',
                    Resource::ProductMedia => 'product_media',
                    default => strtolower($kind->name),
                });
                $after = $kind === Resource::Variant
                    ? (array) DB::table('product_variants')->where('id', $key)->firstOrFail(['active', 'rts_enabled', 'rts_price_amount'])
                    : $data;
                app(Journal::class)->audit($event, $subject, $key, $actor->id, $before ? (array) $before : null, $after);
                app(Outbox::class)->record($event, $subject->value, $key, ['product_id' => $kind === Resource::Product ? $key : $product]);

                return $this->show($kind, $key, $id === null ? 201 : 200);
            })->header('Cache-Control', 'private, no-store');
        } catch (QueryException $e) {
            throw match ($e->getCode()) {
                '23505' => new ApiProblem(409, 'DUPLICATE_RESOURCE', 'SKU, option combination, file, slug or code is already in use.'),
                '23503', '23514' => ValidationException::withMessages(['fields' => 'Invalid reference or catalog constraint.']),
                default => $e,
            };
        }
    }
}
