<?php

namespace App\Modules\Foundation\Audit;

enum Subject: string
{
    case User = 'user';
    case Role = 'role';
    case Order = 'order';
    case ChargeConsent = 'charge_consent';
    case Cart = 'cart';
    case Campaign = 'preorder_campaign';
    case CampaignVariant = 'preorder_campaign_variant';
    case Allocation = 'preorder_allocation';
    case Batch = 'procurement_batch';
    case RequestOrder = 'request_order';
    case Quotation = 'quotation';
    case Payment = 'payment';
    case Reconciliation = 'payment_reconciliation';
    case BankAccount = 'bank_account';
    case Invoice = 'invoice';
    case Refund = 'refund';
    case ReturnCase = 'return';
    case Courier = 'courier';
    case CourierService = 'courier_service';
    case ShippingRate = 'shipping_rate';
    case Shipment = 'shipment';
    case Inventory = 'inventory';
    case StockReservation = 'stock_reservation';
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
