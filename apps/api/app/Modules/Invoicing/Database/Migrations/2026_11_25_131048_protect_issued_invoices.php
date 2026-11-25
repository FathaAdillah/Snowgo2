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
            ALTER TABLE invoices ADD replaces_invoice_id bigint UNIQUE REFERENCES invoices, ADD paid_at timestamptz,
                ADD CONSTRAINT invoices_status_check CHECK(status IN ('DRAFT','ISSUED','VOID','PAID'));
            CREATE INDEX invoices_order_status_due ON invoices(order_id,status,due_at);
            CREATE TRIGGER invoice_snapshot BEFORE DELETE OR UPDATE OF id,number,order_id,replaces_invoice_id,purpose,source_ref,
                currency,subtotal_amount,tax_amount,discount_amount,shipping_amount,total_amount,issued_at,due_at ON invoices
                FOR EACH ROW WHEN (OLD.status <> 'DRAFT') EXECUTE FUNCTION reject_foundation_rewrite();
            CREATE TRIGGER invoice_status_guard BEFORE UPDATE OF status ON invoices FOR EACH ROW
                WHEN (OLD.status <> NEW.status AND NOT (
                    (OLD.status='DRAFT' AND NEW.status IN ('ISSUED','VOID')) OR (OLD.status='ISSUED' AND NEW.status IN ('PAID','VOID'))
                )) EXECUTE FUNCTION reject_foundation_rewrite();
            CREATE OR REPLACE FUNCTION guard_invoice_lines() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF TG_OP <> 'INSERT' THEN
                    PERFORM 1 FROM invoices WHERE id=OLD.invoice_id AND status='DRAFT' FOR SHARE;
                    IF NOT FOUND THEN RAISE EXCEPTION 'Issued invoice lines cannot change'; END IF;
                END IF;
                IF TG_OP <> 'DELETE' THEN
                    PERFORM 1 FROM invoices WHERE id=NEW.invoice_id AND status='DRAFT' AND
                        (NEW.order_item_id IS NULL OR order_id=(SELECT order_id FROM order_items WHERE id=NEW.order_item_id)) FOR SHARE;
                    IF NOT FOUND THEN RAISE EXCEPTION 'Invalid parent for invoice line'; END IF;
                    RETURN NEW;
                END IF;
                RETURN OLD;
            END $$;
            CREATE TRIGGER invoice_line_snapshot BEFORE INSERT OR UPDATE OR DELETE ON invoice_items FOR EACH ROW EXECUTE FUNCTION guard_invoice_lines();
            CREATE TABLE invoice_documents (
                invoice_id bigint PRIMARY KEY REFERENCES invoices, object_id uuid NOT NULL UNIQUE DEFAULT gen_random_uuid(), status varchar(12) NOT NULL DEFAULT 'PENDING'
                    CHECK(status IN ('PENDING','RENDERING','FAILED','READY')),
                token uuid, lease_until timestamptz, file_id bigint UNIQUE REFERENCES stored_files,
                CHECK((status='READY')=(file_id IS NOT NULL)),
                CHECK((status='RENDERING')=(token IS NOT NULL)), CHECK((token IS NULL)=(lease_until IS NULL))
            );
            INSERT INTO invoice_documents(invoice_id) SELECT id FROM invoices WHERE status <> 'DRAFT';
            SQL);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            LOCK TABLE invoices,invoice_documents IN ACCESS EXCLUSIVE MODE;
            DO $$ BEGIN IF EXISTS(SELECT FROM invoices WHERE status <> 'DRAFT') THEN
                RAISE EXCEPTION 'Cannot unfreeze invoice history'; END IF; END $$;
            DROP TABLE invoice_documents;
            DROP TRIGGER invoice_line_snapshot ON invoice_items;
            DROP FUNCTION guard_invoice_lines();
            DROP TRIGGER invoice_snapshot ON invoices;
            DROP TRIGGER invoice_status_guard ON invoices;
            DROP INDEX invoices_order_status_due;
            ALTER TABLE invoices DROP replaces_invoice_id,DROP paid_at,DROP CONSTRAINT invoices_status_check;
            SQL);
    }
};
