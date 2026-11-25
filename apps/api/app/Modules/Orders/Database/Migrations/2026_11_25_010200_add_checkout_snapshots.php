<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE SEQUENCE order_number_seq OWNED BY orders.number;
            ALTER TABLE orders ADD checkout_id bigint REFERENCES checkouts, ADD campaign_id bigint REFERENCES preorder_campaigns,
                ADD term varchar(8) NOT NULL DEFAULT 'FULL' CHECK(term IN ('FULL','DEPOSIT')),
                ADD status varchar(24) NOT NULL DEFAULT 'PENDING_PAYMENT', ADD payment_status varchar(24) NOT NULL DEFAULT 'UNPAID',
                ADD fulfillment_status varchar(24) NOT NULL DEFAULT 'UNFULFILLED', ADD currency char(3) NOT NULL DEFAULT 'IDR',
                ADD subtotal_amount bigint NOT NULL DEFAULT 0 CHECK(subtotal_amount>=0), ADD discount_amount bigint NOT NULL DEFAULT 0 CHECK(discount_amount>=0),
                ADD tax_amount bigint NOT NULL DEFAULT 0 CHECK(tax_amount>=0), ADD shipping_amount bigint NOT NULL DEFAULT 0 CHECK(shipping_amount>=0),
                ADD total_amount bigint NOT NULL DEFAULT 0 CHECK(total_amount=subtotal_amount-discount_amount+tax_amount+shipping_amount),
                ADD notes text, ADD created_at timestamptz NOT NULL DEFAULT now(), ADD completed_at timestamptz, ADD campaign_terms jsonb,
                ADD CHECK(status IN ('PENDING_PAYMENT','CONFIRMED','CANCELLED','EXPIRED','COMPLETED')),
                ADD CHECK(payment_status IN ('UNPAID','PARTIALLY_PAID','PAID','PARTIALLY_REFUNDED','REFUNDED')),
                ADD CHECK(fulfillment_status IN ('UNFULFILLED','AWAITING_STOCK','READY_TO_PACK','PACKING','PACKED','READY_FOR_DISPATCH','PARTIALLY_SHIPPED','SHIPPED','DELIVERED','RETURNED')),
                ADD CHECK(checkout_id IS NULL OR ((selling_method='READY_TO_SHIP' AND campaign_id IS NULL AND term='FULL') OR (selling_method='PREORDER' AND campaign_id IS NOT NULL)));
            CREATE UNIQUE INDEX checkout_rts_order ON orders(checkout_id) WHERE selling_method='READY_TO_SHIP';
            CREATE UNIQUE INDEX checkout_campaign_order ON orders(checkout_id,campaign_id) WHERE selling_method='PREORDER';
            CREATE INDEX ON orders(user_id,created_at);
            CREATE INDEX ON orders(status,payment_due_at);
            ALTER TABLE order_items ADD public_id char(26) UNIQUE, ADD product_snapshot varchar(200), ADD variant_snapshot varchar(160),
                ADD unit_amount bigint CHECK(unit_amount>=0), ADD tax_amount bigint CHECK(tax_amount>=0), ADD weight_g integer,
                ADD term varchar(8) CHECK(term IN ('FULL','DEPOSIT')), ADD initial_unit_amount bigint CHECK(initial_unit_amount BETWEEN 0 AND unit_amount),
                ADD discount_amount bigint NOT NULL DEFAULT 0 CHECK(discount_amount>=0),
                ADD initial_tax bigint CHECK(initial_tax BETWEEN 0 AND tax_amount), ADD tax_class_id bigint REFERENCES tax_classes,
                ADD tax_bps integer CHECK(tax_bps BETWEEN 0 AND 10000),
                ADD received_qty integer NOT NULL DEFAULT 0 CHECK(received_qty BETWEEN 0 AND qty),
                ADD cancelled_qty integer NOT NULL DEFAULT 0 CHECK(cancelled_qty>=0), ADD shipped_qty integer NOT NULL DEFAULT 0 CHECK(shipped_qty>=0),
                ADD CHECK(cancelled_qty::bigint+shipped_qty<=qty);
            CREATE TABLE order_addresses (
                order_id bigint PRIMARY KEY REFERENCES orders, source_address_id bigint REFERENCES addresses, recipient varchar(120) NOT NULL,
                phone varchar(32) NOT NULL, line1 varchar(255) NOT NULL, line2 varchar(255), postal_code varchar(12) NOT NULL,
                district_id bigint NOT NULL REFERENCES regions, region_snapshot jsonb NOT NULL
            );
            CREATE TRIGGER order_address_snapshot BEFORE UPDATE OR DELETE ON order_addresses FOR EACH ROW EXECUTE FUNCTION reject_foundation_rewrite();
            CREATE TRIGGER checkout_line_snapshot BEFORE DELETE OR UPDATE OF order_id,public_id,variant_id,sku_snapshot,product_snapshot,variant_snapshot,
                qty,weight_g,unit_amount,discount_amount,tax_amount,term,initial_unit_amount,initial_tax,tax_class_id,tax_bps ON order_items
                FOR EACH ROW WHEN (OLD.public_id IS NOT NULL) EXECUTE FUNCTION reject_foundation_rewrite();
            SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TABLE order_addresses;
            DROP TRIGGER checkout_line_snapshot ON order_items;
            ALTER TABLE order_items DROP public_id,DROP product_snapshot,DROP variant_snapshot,DROP unit_amount,DROP tax_amount,DROP weight_g,DROP term,DROP initial_unit_amount,DROP discount_amount,DROP received_qty,DROP cancelled_qty,DROP shipped_qty,DROP initial_tax,DROP tax_class_id,DROP tax_bps;
            ALTER TABLE orders DROP checkout_id,DROP campaign_id,DROP term,DROP status,DROP payment_status,DROP fulfillment_status,DROP currency,
                DROP subtotal_amount,DROP discount_amount,DROP tax_amount,DROP shipping_amount,DROP total_amount,DROP notes,DROP created_at,DROP completed_at,DROP campaign_terms;
            DROP SEQUENCE order_number_seq;
            SQL);
    }
};
