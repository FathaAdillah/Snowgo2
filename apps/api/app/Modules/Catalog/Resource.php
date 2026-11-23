<?php

namespace App\Modules\Catalog;

enum Resource: string
{
    case Product = 'products';
    case Variant = 'product_variants';
    case Category = 'categories';
    case Brand = 'brands';
    case Collection = 'collections';
    case Tag = 'tags';
    case Attribute = 'attributes';
    case AttributeValue = 'attribute_values';
    case ProductMedia = 'product_media';

    public function permission(string $action): string
    {
        $name = match ($this) {
            self::ProductMedia => 'media',
            self::AttributeValue => 'attribute_value',
            default => strtolower($this->name),
        };

        return "catalog.$name.$action";
    }

    public function path(): string
    {
        return $this === self::Variant ? 'variants' : str_replace('_', '-', $this->value);
    }

    public function searchField(): string
    {
        return match ($this) {
            self::Variant => 'sku',
            self::AttributeValue => 'label',
            self::ProductMedia => 'alt',
            default => 'name',
        };
    }
}
