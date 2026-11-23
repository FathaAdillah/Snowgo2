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
    case Product = 'product';
    case File = 'stored_file';
    case Region = 'region';
    case Reason = 'reason_code';
    case Unit = 'unit';
    case TaxClass = 'tax_class';
}
