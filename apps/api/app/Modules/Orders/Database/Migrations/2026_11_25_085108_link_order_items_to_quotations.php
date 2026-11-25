<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE order_items ADD quotation_item_id bigint UNIQUE REFERENCES quotation_items,
                ADD initial_line_amount bigint CHECK(initial_line_amount BETWEEN 0 AND unit_amount*qty);
            ALTER TABLE order_items ADD quotation_options varchar(1000);
            CREATE TRIGGER quotation_order_line BEFORE UPDATE OF quotation_item_id,initial_line_amount,quotation_options ON order_items
                FOR EACH ROW WHEN (OLD.quotation_item_id IS NOT NULL) EXECUTE FUNCTION reject_foundation_rewrite();
            CREATE TRIGGER quotation_order_freeze BEFORE UPDATE OF number,user_id,checkout_id,campaign_id,selling_method,term,currency,
                subtotal_amount,discount_amount,tax_amount,shipping_amount,total_amount,notes,created_at ON orders
                FOR EACH ROW WHEN (OLD.request_order_id IS NOT NULL) EXECUTE FUNCTION reject_foundation_rewrite();
            SQL);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            LOCK TABLE order_items IN ACCESS EXCLUSIVE MODE;
            DO $$ BEGIN IF EXISTS(SELECT FROM order_items WHERE quotation_item_id IS NOT NULL) THEN RAISE EXCEPTION 'Cannot discard quotation conversions'; END IF; END $$;
            DROP TRIGGER quotation_order_freeze ON orders;
            DROP TRIGGER quotation_order_line ON order_items;
            ALTER TABLE order_items DROP quotation_item_id,DROP initial_line_amount,DROP quotation_options;
            SQL);
    }
};
