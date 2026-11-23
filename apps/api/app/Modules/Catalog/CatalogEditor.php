<?php

namespace App\Modules\Catalog;

use App\Modules\Foundation\Files\StoredFiles;
use App\Support\Http\ApiProblem;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class CatalogEditor
{
    public const RELATIONS = ['category_ids' => ['categories', 'category_id'], 'collection_ids' => ['collections', 'collection_id'], 'tag_ids' => ['tags', 'tag_id']];

    /** @param array<string,mixed> $data */
    public function save(Resource $kind, array $data, ?object $before): int
    {
        $id = $before->id ?? null;
        $fields = array_replace((array) $before, $data);
        $columns = Arr::except($data, [...array_keys(self::RELATIONS), 'attribute_value_ids']);

        switch ($kind) {
            case Resource::Product:
                foreach (['brand_id' => 'brands', 'tax_class_id' => 'tax_classes'] as $field => $table) {
                    if (isset($data[$field])) {
                        self::activeIds($table, [$data[$field]], $field);
                    }
                }
                if (isset($data['status']) && $data['status'] !== ($before->status ?? null)) {
                    $columns['published_at'] = $data['status'] === 'PUBLISHED' ? now() : null;
                }
                break;
            case Resource::Variant:
                self::immutableParent($before, $data, 'product_id');
                if (! $before || $fields['active'] || (int) $fields['unit_id'] !== (int) $before->unit_id) {
                    self::activeIds('units', [$fields['unit_id']], 'unit_id');
                }
                if ($fields['rts_enabled'] && ! isset($fields['rts_price_amount'])) {
                    self::invalid('rts_price_amount', 'Set a whole IDR price before enabling RTS.');
                }
                if (isset($data['attribute_value_ids']) || ($before && $fields['active'] && ! $before->active)) {
                    $ids = $data['attribute_value_ids'] ?? DB::table('variant_attribute_values')->where('variant_id', $id)->pluck('value_id')->all();
                    $values = DB::table('attribute_values as v')->join('attributes as a', 'a.id', '=', 'v.attribute_id')
                        ->where(['v.active' => true, 'a.active' => true])->whereIntegerInRaw('v.id', $ids)
                        ->orderBy('v.id')->get(['v.id', 'v.attribute_id']);
                    if ($values->count() !== count($ids) || $values->pluck('attribute_id')->unique()->count() !== count($ids)) {
                        self::invalid('attribute_value_ids', 'Choose at most one active value per active attribute.');
                    }
                    $columns['option_key'] = $values->isEmpty() ? 'default' : hash('sha256', $values->pluck('id')->implode(','));
                }
                break;
            case Resource::Category:
                $parent = $data['parent_id'] ?? null;
                if ($parent !== null) {
                    self::activeIds('categories', [$parent], 'parent_id');
                    $ancestors = DB::select('WITH RECURSIVE chain AS (SELECT id,parent_id FROM categories WHERE id=?
                        UNION SELECT c.id,c.parent_id FROM categories c JOIN chain ON chain.parent_id=c.id) SELECT id FROM chain', [$parent]);
                    if ($id !== null && in_array($id, array_column($ancestors, 'id'), true)) {
                        self::invalid('parent_id', 'Category hierarchy must not contain a cycle.');
                    }
                } elseif ($fields['active'] && isset($fields['parent_id'])) {
                    self::activeIds('categories', [$fields['parent_id']], 'parent_id');
                }
                if ($id !== null && ! $fields['active'] && DB::table('categories')->where(['parent_id' => $id, 'active' => true])->exists()) {
                    throw new ApiProblem(409, 'INVALID_STATE_TRANSITION', 'Archive active children first.');
                }
                break;
            case Resource::AttributeValue:
                self::immutableParent($before, $data, 'attribute_id');
                if ($fields['active'] ?? true) {
                    self::activeIds('attributes', [$fields['attribute_id']], 'attribute_id');
                }
                break;
            case Resource::ProductMedia:
                self::immutableParent($before, $data, 'product_id');
                if ($before !== null && array_key_exists('variant_id', $data) && ! isset($data['file_id'])) {
                    self::invalid('file_id', 'Supply the file reference when changing the media owner.');
                }
                if (isset($fields['variant_id']) && ! DB::table('product_variants')->where(['id' => $fields['variant_id'], 'product_id' => $fields['product_id']])->exists()) {
                    self::invalid('variant_id', 'Variant must belong to this product.');
                }
                if (isset($data['file_id'])) {
                    $file = app(StoredFiles::class)->requireReady($data['file_id'], isset($fields['variant_id']) ? 'variant' : 'product',
                        $fields['variant_id'] ?? $fields['product_id'], 'catalog_media');
                    $columns['file_id'] = $file->id;
                    if ($file->id !== ($before->file_id ?? null)) {
                        $columns['published_key'] = null;
                    }
                }
                break;
            default:
                break;
        }
        $table = DB::table($kind->value);
        if ($id === null) {
            $id = $table->insertGetId($columns);
        } else {
            $table->where('id', $id)->update($columns + ['updated_at' => now()]);
        }
        if ($kind === Resource::Product) {
            foreach (self::RELATIONS as $field => [$related, $fk]) {
                if (isset($data[$field])) {
                    self::activeIds($related, $data[$field], $field);
                    $pivot = DB::table("product_$related");
                    (clone $pivot)->where('product_id', $id)->delete();
                    $pivot->insert(array_map(fn ($value) => ['product_id' => $id, $fk => $value], $data[$field]));
                }
            }
        }
        if (isset($values)) {
            DB::table('variant_attribute_values')->where('variant_id', $id)->delete();
            DB::table('variant_attribute_values')->insert($values->map(fn ($value) => ['variant_id' => $id, 'attribute_id' => $value->attribute_id, 'value_id' => $value->id])->all());
        }
        $this->checkPublishState($kind, $id, $fields);

        return $id;
    }

    /** @param array<string,mixed> $fields */
    private function checkPublishState(Resource $kind, int $id, array $fields): void
    {
        if (in_array($kind, [Resource::Category, Resource::Variant, Resource::Product], true)) {
            $products = ProductDirectory::published();
            match ($kind) {
                Resource::Category => $products->whereIn('id', DB::table('product_categories')->where('category_id', $id)->select('product_id')),
                Resource::Variant => $products->where('id', $fields['product_id']),
                default => $products->where('id', $id),
            };
            if ($products->where(fn ($q) => $q->whereNotExists(DB::table('product_variants')->whereColumn('product_id', 'products.id')->where('active', true)->whereNull('deleted_at'))
                ->orWhereNotExists(DB::table('product_categories as pc')->join('categories as c', 'c.id', '=', 'pc.category_id')
                    ->whereColumn('pc.product_id', 'products.id')->where('c.active', true)))->exists()) {
                throw new ApiProblem(409, 'INVALID_STATE_TRANSITION', 'A published product needs an active category and variant.');
            }
        }
        if (in_array($kind, [Resource::Attribute, Resource::AttributeValue], true) && ! ($fields['active'] ?? true)) {
            if (DB::table('variant_attribute_values as o')->join('product_variants as v', 'v.id', '=', 'o.variant_id')
                ->where($kind === Resource::Attribute ? 'o.attribute_id' : 'o.value_id', $id)->where('v.active', true)->exists()) {
                throw new ApiProblem(409, 'INVALID_STATE_TRANSITION', 'Option is used by an active variant.');
            }
        }
    }

    /** @param array<string,mixed> $data */
    private static function immutableParent(?object $before, array $data, string $field): void
    {
        if ($before && isset($data[$field]) && (int) $data[$field] !== $before->$field) {
            self::invalid($field, 'Parent cannot change.');
        }
    }

    /** @param list<int|string> $ids */
    private static function activeIds(string $table, array $ids, string $field): void
    {
        if ($ids && count(DB::table($table)->where('active', true)->whereIntegerInRaw('id', $ids)->sharedLock()->pluck('id')->all()) !== count($ids)) {
            self::invalid($field, 'Select existing active records.');
        }
    }

    private static function invalid(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
