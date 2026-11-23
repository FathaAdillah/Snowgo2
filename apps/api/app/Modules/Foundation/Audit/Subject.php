<?php

namespace App\Modules\Foundation\Audit;

enum Subject: string
{
    case User = 'user';
    case Role = 'role';
    case Order = 'order';
    case Payment = 'payment';
    case Invoice = 'invoice';
    case Refund = 'refund';
    case ReturnCase = 'return';
    case Shipment = 'shipment';
    case Inventory = 'inventory';
    case Warehouse = 'warehouse';
    case Product = 'product';
    case Variant = 'variant';
    case Category = 'category';
    case Brand = 'brand';
    case Collection = 'collection';
    case Tag = 'tag';
    case Attribute = 'attribute';
    case AttributeValue = 'attribute_value';
    case ProductMedia = 'product_media';
    case File = 'stored_file';
    case Region = 'region';
    case Reason = 'reason_code';
    case Unit = 'unit';
    case TaxClass = 'tax_class';
}
