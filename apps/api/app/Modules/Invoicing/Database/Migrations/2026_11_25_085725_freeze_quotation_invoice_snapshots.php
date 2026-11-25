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
            CREATE TRIGGER quotation_invoice_snapshot BEFORE DELETE OR UPDATE OF number,order_id,purpose,source_ref,currency,subtotal_amount,
                tax_amount,discount_amount,shipping_amount,total_amount,issued_at,due_at ON invoices
                FOR EACH ROW WHEN (OLD.source_ref LIKE 'quotation:%') EXECUTE FUNCTION reject_foundation_rewrite();
            CREATE OR REPLACE FUNCTION guard_quotation_invoice_line() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF TG_OP <> 'INSERT' AND EXISTS(SELECT FROM invoices WHERE id=OLD.invoice_id AND source_ref LIKE 'quotation:%') THEN
                    RAISE EXCEPTION 'Cannot rewrite quotation invoice lines';
                END IF;
                IF TG_OP <> 'DELETE' AND EXISTS(SELECT FROM invoices i JOIN orders o ON o.id=i.order_id
                    JOIN request_orders r ON r.id=o.request_order_id WHERE i.id=NEW.invoice_id AND i.source_ref LIKE 'quotation:%' AND r.status <> 'QUOTED') THEN
                    RAISE EXCEPTION 'Cannot append to issued quotation invoice';
                END IF;
                IF TG_OP='DELETE' THEN RETURN OLD; END IF;
                RETURN NEW;
            END $$;
            CREATE TRIGGER quotation_invoice_item_snapshot BEFORE INSERT OR UPDATE OR DELETE ON invoice_items FOR EACH ROW EXECUTE FUNCTION guard_quotation_invoice_line();
            SQL);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            LOCK TABLE invoices IN ACCESS EXCLUSIVE MODE;
            DO $$ BEGIN IF EXISTS(SELECT FROM invoices WHERE source_ref LIKE 'quotation:%') THEN RAISE EXCEPTION 'Cannot unfreeze issued quotation invoices'; END IF; END $$;
            DROP TRIGGER quotation_invoice_item_snapshot ON invoice_items;
            DROP FUNCTION guard_quotation_invoice_line();
            DROP TRIGGER quotation_invoice_snapshot ON invoices;
            SQL);
    }
};
