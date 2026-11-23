<?php

namespace App\Modules\Geography;

use App\Models\User;
use App\Modules\Foundation\Audit\Journal;
use App\Modules\Foundation\Idempotency\IdempotentMutation;
use App\Modules\Foundation\Outbox\Outbox;
use App\Support\Http\ApiInput;
use App\Support\Http\ApiProblem;
use App\Support\Http\ApiResponse;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class References
{
    public const PAGE = ApiInput::PAGE;

    public const ADMIN_FILTERS = [...self::PAGE, 'q' => 'sometimes|required|string|max:120', 'active' => 'sometimes|string|in:0,1,true,false'];

    public const CODE = 'regex:/\A[A-Za-z0-9_.-]+\z/D';

    public const REGION_KINDS = 'in:COUNTRY,PROVINCE,CITY,DISTRICT';

    private function query(Master $master, bool $public = false): Builder
    {
        $table = $master->table();
        if ($master === Master::Region) {
            $query = DB::table('regions as r')->leftJoin('regions as p', 'p.id', '=', 'r.parent_id')
                ->select('r.code', 'r.name', 'r.kind', 'p.code as parent_code');

            return $public ? $query->where('r.active', true) : $query->addSelect('r.id', 'r.parent_id', 'r.active');
        }
        $query = DB::table($table.' as r')->select('r.id', 'r.code', 'r.active');

        return match ($master) {
            Master::Reason => $query->addSelect('r.domain', 'r.label'),
            Master::Tax => $query->addSelect('r.name', 'r.rate_bps'),
            default => $query->addSelect('r.name'),
        };
    }

    /** @param array<string,mixed> $filters */
    public function page(Request $request, Master $master, array $filters, bool $public = false): JsonResponse
    {
        ApiInput::fields($request, $filters);
        $query = $this->query($master, $public);
        if (isset($filters['kind'])) {
            $query->where('r.kind', $filters['kind']);
        }
        if (isset($filters['parent'])) {
            $query->where('p.code', $filters['parent']);
        }
        if (isset($filters['active'])) {
            $query->where('r.active', filter_var($filters['active'], FILTER_VALIDATE_BOOL));
        }
        if (isset($filters['q'])) {
            $term = '%'.addcslashes($filters['q'], '\\%_').'%';
            $query->where(fn ($q) => $q->whereLike('r.code', $term)->orWhereLike($master === Master::Reason ? 'r.label' : 'r.name', $term));
        }
        $page = $query->orderBy('r.code')->orderBy('r.id')->paginate((int) ($filters['per_page'] ?? 20));

        return ApiResponse::success($page->items(), meta: ['page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'last_page' => $page->lastPage()]);
    }

    public function show(Master $master, int $id, int $status = 200): JsonResponse
    {
        $row = $this->query($master)->where('r.id', $id)->first() ?? abort(404);

        return ApiResponse::success($row, status: $status);
    }

    /** @param array<string,mixed> $data */
    public function save(Request $request, Master $master, array $data, ?int $id = null): JsonResponse
    {
        ApiInput::fields($request, $data);
        $actor = $request->user();
        assert($actor instanceof User);
        $ability = $master->permission($id === null ? 'create' : 'update');
        try {
            return app(IdempotentMutation::class)->run($request, function () use ($actor, $ability) {
                abort_unless($actor->can($ability), 403);

                return 'user:'.$actor->id;
            }, function () use ($master, $data, $id, $actor, $ability) {
                abort_unless($actor->fresh()?->can($ability), 403);
                if ($master === Master::Region) {
                    self::lockRegions();
                }
                $table = DB::table($master->table());
                $before = null;
                if ($id !== null) {
                    $target = $table->where('id', $id);
                    $before = (array) ($target->lockForUpdate()->first() ?? abort(404));
                    if ($master === Master::Region && array_key_exists('active', $data) && ! $data['active']
                        && DB::table('regions')->where(['parent_id' => $id, 'active' => true])->exists()) {
                        throw new ApiProblem(409, 'INVALID_STATE_TRANSITION', 'Archive active children first.');
                    }
                    $target->update($data + ['updated_at' => now()]);
                }
                $key = $id ?? $table->insertGetId($data);
                $event = ($master === Master::Tax ? 'TaxClass' : $master->name).'Changed';
                app(Journal::class)->audit($event, $master->subject(), $key, $actor->id, $before, array_replace($before ?? [], $data));
                app(Outbox::class)->record($event, $master->subject()->value, $key, []);

                return $this->show($master, $key, $id === null ? 201 : 200);
            });
        } catch (QueryException $e) {
            throw match ($e->getCode()) {
                '23505' => new ApiProblem(409, 'DUPLICATE_RESOURCE', 'Code already in use.'),
                '23503', '23514' => ValidationException::withMessages(['region' => 'Invalid hierarchy: check the parent level, country ID and active descendants.']),
                default => $e,
            };
        }
    }

    public static function lockRegions(): void
    {
        DB::select("SELECT pg_advisory_xact_lock(hashtextextended('geography:regions', 0))");
    }
}
