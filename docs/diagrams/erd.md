# Entity relationship diagrams

Relasi mayoritas mengikuti foreign key. Partial unique dan relasi polimorfik dirinci di [kamus data](../04-DATABASE-SCHEMA.md). Nama tabel uppercase di diagram untuk keterbacaan.

## Identity

```mermaid
erDiagram
    USERS ||--o{ STAFF_INVITATIONS : "user_id"
    USERS {
        bigint id PK
    }
    USERS ||--o{ SOCIAL_ACCOUNTS : "user_id"
    USERS o|--o{ SESSIONS : "user_id"
    PASSWORD_RESET_TOKENS {
        bigint id PK
    }
    USERS ||--o{ OAUTH_LINK_REQUESTS : "user_id"
    USERS ||--o| CUSTOMER_PROFILES : "user_id"
    USERS ||--o{ ADDRESSES : "user_id"
    REGIONS ||--o{ ADDRESSES : "district_id"
    USERS ||--o{ WISHLISTS : "user_id"
    PRODUCTS ||--o{ WISHLISTS : "product_id"
    REGIONS o|--o{ REGIONS : "parent_id"
    REASON_CODES {
        bigint id PK
    }
    UNITS {
        bigint id PK
    }
    TAX_CLASSES {
        bigint id PK
    }
    ROLES {
        bigint id PK
    }
    PERMISSIONS {
        bigint id PK
    }
    ROLES ||--o{ MODEL_HAS_ROLES : "role_id"
    PERMISSIONS ||--o{ MODEL_HAS_PERMISSIONS : "permission_id"
    ROLES ||--o{ ROLE_HAS_PERMISSIONS : "role_id"
    PERMISSIONS ||--o{ ROLE_HAS_PERMISSIONS : "permission_id"
    USERS o|--o{ SETTINGS : "updated_by"
    INTEGRATION_CONFIGS {
        bigint id PK
    }
```

## Catalog

```mermaid
erDiagram
    BRANDS o|--o{ PRODUCTS : "brand_id"
    TAX_CLASSES o|--o{ PRODUCTS : "tax_class_id"
    PRODUCTS ||--o{ PRODUCT_VARIANTS : "product_id"
    UNITS ||--o{ PRODUCT_VARIANTS : "unit_id"
    CATEGORIES o|--o{ CATEGORIES : "parent_id"
    BRANDS {
        bigint id PK
    }
    COLLECTIONS {
        bigint id PK
    }
    TAGS {
        bigint id PK
    }
    ATTRIBUTES {
        bigint id PK
    }
    ATTRIBUTES ||--o{ ATTRIBUTE_VALUES : "attribute_id"
    PRODUCTS ||--o{ PRODUCT_CATEGORIES : "product_id"
    CATEGORIES ||--o{ PRODUCT_CATEGORIES : "category_id"
    PRODUCTS ||--o{ PRODUCT_COLLECTIONS : "product_id"
    COLLECTIONS ||--o{ PRODUCT_COLLECTIONS : "collection_id"
    PRODUCTS ||--o{ PRODUCT_TAGS : "product_id"
    TAGS ||--o{ PRODUCT_TAGS : "tag_id"
    PRODUCT_VARIANTS ||--o{ VARIANT_ATTRIBUTE_VALUES : "variant_id"
    ATTRIBUTES ||--o{ VARIANT_ATTRIBUTE_VALUES : "attribute_id"
    ATTRIBUTE_VALUES ||--o{ VARIANT_ATTRIBUTE_VALUES : "value_id"
    PRODUCTS ||--o{ PRODUCT_MEDIA : "product_id"
    PRODUCT_VARIANTS o|--o{ PRODUCT_MEDIA : "variant_id"
    STORED_FILES ||--o{ PRODUCT_MEDIA : "file_id"
```

## Inventory

```mermaid
erDiagram
    REGIONS ||--o{ WAREHOUSES : "district_id"
    WAREHOUSES ||--o{ INVENTORY_LEVELS : "warehouse_id"
    PRODUCT_VARIANTS ||--o{ INVENTORY_LEVELS : "variant_id"
    INVENTORY_LEVELS ||--o{ INVENTORY_MOVEMENTS : "level_id"
    USERS o|--o{ INVENTORY_MOVEMENTS : "actor_id"
    REASON_CODES o|--o{ INVENTORY_MOVEMENTS : "reason_id"
    INVENTORY_LEVELS ||--o{ STOCK_RESERVATIONS : "level_id"
    ORDER_ITEMS ||--o{ STOCK_RESERVATIONS : "order_item_id"
    INVENTORY_LEVELS ||--o{ STOCK_ADJUSTMENTS : "level_id"
    USERS ||--o{ STOCK_ADJUSTMENTS : "actor_id"
    REASON_CODES ||--o{ STOCK_ADJUSTMENTS : "reason_id"
    INVENTORY_MOVEMENTS ||--o{ STOCK_ADJUSTMENTS : "movement_id"
    INVENTORY_LEVELS ||--o{ STOCK_TRANSFERS : "source_level_id"
    INVENTORY_LEVELS ||--o{ STOCK_TRANSFERS : "target_level_id"
    USERS ||--o{ STOCK_TRANSFERS : "actor_id"
```

## Commerce

```mermaid
erDiagram
    USERS ||--o{ CARTS : "user_id"
    CARTS ||--o{ CART_ITEMS : "cart_id"
    PRODUCT_VARIANTS ||--o{ CART_ITEMS : "variant_id"
    PREORDER_CAMPAIGN_VARIANTS o|--o{ CART_ITEMS : "campaign_variant_id"
    USERS ||--o{ CHECKOUTS : "user_id"
    CARTS ||--o{ CHECKOUTS : "cart_id"
    USERS ||--o{ ORDERS : "user_id"
    CHECKOUTS o|--o{ ORDERS : "checkout_id"
    PREORDER_CAMPAIGNS o|--o{ ORDERS : "campaign_id"
    REQUEST_ORDERS o|--o{ ORDERS : "request_order_id"
    ORDERS ||--o{ ORDER_ITEMS : "order_id"
    PRODUCT_VARIANTS o|--o{ ORDER_ITEMS : "variant_id"
    QUOTATION_ITEMS o|--o{ ORDER_ITEMS : "quotation_item_id"
    ORDERS ||--o| ORDER_ADDRESSES : "order_id"
    ADDRESSES o|--o{ ORDER_ADDRESSES : "source_address_id"
    REGIONS ||--o{ ORDER_ADDRESSES : "district_id"
    ORDERS ||--o{ ORDER_CHARGE_CONSENTS : "order_id"
    SHIPMENTS o|--o{ ORDER_CHARGE_CONSENTS : "shipment_id"
    INVOICES o|--o{ ORDER_CHARGE_CONSENTS : "invoice_id"
```

## Preorder

```mermaid
erDiagram
    PREORDER_CAMPAIGNS {
        bigint id PK
    }
    PREORDER_CAMPAIGNS ||--o{ PREORDER_CAMPAIGN_VARIANTS : "campaign_id"
    PRODUCT_VARIANTS ||--o{ PREORDER_CAMPAIGN_VARIANTS : "variant_id"
    PREORDER_CAMPAIGN_VARIANTS ||--o{ PREORDER_ALLOCATIONS : "campaign_variant_id"
    ORDER_ITEMS ||--o| PREORDER_ALLOCATIONS : "order_item_id"
    PREORDER_CAMPAIGNS o|--o{ PROCUREMENT_BATCHES : "campaign_id"
    REQUEST_ORDERS o|--o{ PROCUREMENT_BATCHES : "request_order_id"
    PROCUREMENT_BATCHES ||--o{ PROCUREMENT_BATCH_ITEMS : "batch_id"
    ORDER_ITEMS ||--o{ PROCUREMENT_BATCH_ITEMS : "order_item_id"
    PROCUREMENT_BATCHES ||--o{ PROCUREMENT_STAGE_EVENTS : "batch_id"
    USERS o|--o{ PROCUREMENT_STAGE_EVENTS : "actor_id"
```

## Request order

```mermaid
erDiagram
    USERS ||--o{ REQUEST_ORDERS : "user_id"
    USERS o|--o{ REQUEST_ORDERS : "assigned_to"
    REQUEST_ORDERS ||--o{ REQUEST_ORDER_ITEMS : "request_order_id"
    REQUEST_ORDERS ||--o{ REQUEST_ORDER_MESSAGES : "request_order_id"
    USERS ||--o{ REQUEST_ORDER_MESSAGES : "author_id"
    REQUEST_ORDERS ||--o{ QUOTATIONS : "request_order_id"
    QUOTATIONS ||--o{ QUOTATION_ITEMS : "quotation_id"
    REQUEST_ORDER_ITEMS ||--o{ QUOTATION_ITEMS : "request_order_item_id"
```

## Payment dan invoicing

```mermaid
erDiagram
    ORDERS ||--o{ INVOICES : "order_id"
    INVOICES o|--o{ INVOICES : "replaces_invoice_id"
    INVOICES ||--o{ INVOICE_ITEMS : "invoice_id"
    ORDER_ITEMS o|--o{ INVOICE_ITEMS : "order_item_id"
    BANK_ACCOUNTS {
        bigint id PK
    }
    USERS ||--o{ PAYMENTS : "user_id"
    BANK_ACCOUNTS o|--o{ PAYMENTS : "bank_account_id"
    USERS o|--o{ PAYMENTS : "verified_by"
    USERS o|--o{ PAYMENTS : "claim_by"
    REASON_CODES o|--o{ PAYMENTS : "rejection_reason_id"
    PAYMENT_RECONCILIATIONS o|--o{ PAYMENTS : "reconciliation_id"
    PAYMENTS ||--o{ PAYMENT_ALLOCATIONS : "payment_id"
    INVOICES ||--o{ PAYMENT_ALLOCATIONS : "invoice_id"
    PAYMENTS ||--o{ PAYMENT_PROOFS : "payment_id"
    STORED_FILES ||--o{ PAYMENT_PROOFS : "file_id"
    USERS ||--o{ PAYMENT_PROOFS : "submitted_by"
    PAYMENTS ||--o{ PAYMENT_RECONCILIATIONS : "payment_id"
    USERS o|--o{ PAYMENT_RECONCILIATIONS : "reviewed_by"
    ORDERS ||--o{ PAYMENT_RECONCILIATIONS : "order_id"
    BANK_ACCOUNTS ||--o{ PAYMENT_RECONCILIATIONS : "bank_account_id"
    STORED_FILES o|--o{ PAYMENT_RECONCILIATIONS : "proof_file_id"
    USERS ||--o{ PAYMENT_RECONCILIATIONS : "handled_by"
    REASON_CODES ||--o{ PAYMENT_RECONCILIATIONS : "reason_id"
```

## Shipping

```mermaid
erDiagram
    COURIERS {
        bigint id PK
    }
    COURIERS ||--o{ COURIER_SERVICES : "courier_id"
    COURIER_SERVICES ||--o{ SHIPPING_RATES : "service_id"
    REGIONS ||--o{ SHIPPING_RATES : "origin_region_id"
    REGIONS ||--o{ SHIPPING_RATES : "destination_region_id"
    USERS ||--o{ SHIPPING_QUOTES : "user_id"
    CARTS o|--o{ SHIPPING_QUOTES : "cart_id"
    ORDERS o|--o{ SHIPPING_QUOTES : "order_id"
    COURIER_SERVICES ||--o{ SHIPPING_QUOTES : "service_id"
    SHIPPING_RATES ||--o{ SHIPPING_QUOTES : "rate_id"
    ORDERS ||--o{ SHIPMENTS : "order_id"
    WAREHOUSES ||--o{ SHIPMENTS : "warehouse_id"
    COURIER_SERVICES o|--o{ SHIPMENTS : "service_id"
    RETURNS o|--o{ SHIPMENTS : "return_id"
    SHIPMENTS ||--o{ SHIPMENT_ITEMS : "shipment_id"
    ORDER_ITEMS ||--o{ SHIPMENT_ITEMS : "order_item_id"
    SHIPMENTS ||--o{ TRACKING_EVENTS : "shipment_id"
```

## Refund dan return

```mermaid
erDiagram
    ORDERS ||--o{ REFUNDS : "order_id"
    RETURNS o|--o{ REFUNDS : "return_id"
    USERS ||--o{ REFUNDS : "requested_by"
    REASON_CODES ||--o{ REFUNDS : "reason_id"
    USERS o|--o{ REFUNDS : "reviewed_by"
    REFUNDS ||--o{ REFUND_ITEMS : "refund_id"
    ORDER_ITEMS ||--o{ REFUND_ITEMS : "order_item_id"
    REFUNDS ||--o{ REFUND_TRANSACTIONS : "refund_id"
    PAYMENT_ALLOCATIONS ||--o{ REFUND_TRANSACTIONS : "payment_allocation_id"
    STORED_FILES ||--o{ REFUND_TRANSACTIONS : "proof_file_id"
    USERS ||--o{ REFUND_TRANSACTIONS : "confirmed_by"
    ORDERS ||--o{ RETURNS : "order_id"
    SHIPMENTS ||--o{ RETURNS : "shipment_id"
    USERS ||--o{ RETURNS : "requested_by"
    REASON_CODES ||--o{ RETURNS : "reason_id"
    RETURNS ||--o{ RETURN_ITEMS : "return_id"
    SHIPMENT_ITEMS ||--o{ RETURN_ITEMS : "shipment_item_id"
```

## Platform, content, promo dan notifikasi

```mermaid
erDiagram
    PROMOTIONS {
        bigint id PK
    }
    PROMOTIONS ||--o{ COUPONS : "promotion_id"
    COUPONS ||--o{ COUPON_USAGES : "coupon_id"
    USERS ||--o{ COUPON_USAGES : "user_id"
    CHECKOUTS ||--o{ COUPON_USAGES : "checkout_id"
    STORED_FILES o|--o{ CONTENT_ENTRIES : "media_id"
    USERS ||--o{ NOTIFICATIONS : "user_id"
    OUTBOX_EVENTS ||--o{ NOTIFICATIONS : "event_id"
    NOTIFICATIONS o|--o{ NOTIFICATION_DELIVERIES : "notification_id"
    OUTBOX_EVENTS ||--o{ NOTIFICATION_DELIVERIES : "event_id"
    USERS ||--o{ NOTIFICATION_DELIVERIES : "user_id"
    USERS o|--o{ STORED_FILES : "uploaded_by"
    USERS o|--o{ STATUS_HISTORIES : "actor_id"
    REASON_CODES o|--o{ STATUS_HISTORIES : "reason_id"
    USERS o|--o{ AUDIT_LOGS : "actor_id"
    IDEMPOTENCY_KEYS {
        bigint id PK
    }
    WEBHOOK_EVENTS {
        bigint id PK
    }
    OUTBOX_EVENTS {
        bigint id PK
    }
    OUTBOX_EVENTS ||--o{ EVENT_CONSUMPTIONS : "event_id"
    FAILED_JOBS {
        bigint id PK
    }
```
