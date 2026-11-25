<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE order_items ADD delivered_qty integer NOT NULL DEFAULT 0 CHECK(delivered_qty BETWEEN 0 AND shipped_qty);
            CREATE TABLE order_transition_holds (
                order_id bigint NOT NULL REFERENCES orders, kind varchar(16) NOT NULL CHECK(kind IN ('PROOF_REVIEW','DISPUTE')),
                source_ref varchar(128) NOT NULL, PRIMARY KEY(order_id,kind,source_ref)
            );
            CREATE TABLE order_action_records (
                order_id bigint PRIMARY KEY REFERENCES orders, action varchar(8) NOT NULL CHECK(action IN ('CANCEL','COMPLETE')),
                reason_code varchar(48), note varchar(1000)
            );
            CREATE TRIGGER order_action_immutable BEFORE UPDATE OR DELETE ON order_action_records FOR EACH ROW EXECUTE FUNCTION reject_foundation_rewrite();
            CREATE TRIGGER checkout_order_snapshot BEFORE UPDATE OF number,user_id,checkout_id,campaign_id,selling_method,term,currency,
                subtotal_amount,discount_amount,tax_amount,shipping_amount,total_amount,campaign_terms,notes,created_at ON orders
                FOR EACH ROW WHEN (OLD.checkout_id IS NOT NULL) EXECUTE FUNCTION reject_foundation_rewrite();
            SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER checkout_order_snapshot ON orders; DROP TABLE order_action_records,order_transition_holds; ALTER TABLE order_items DROP delivered_qty');
    }
};
