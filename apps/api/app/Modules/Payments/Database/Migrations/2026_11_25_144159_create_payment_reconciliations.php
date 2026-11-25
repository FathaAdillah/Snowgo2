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
            CREATE TABLE payment_reconciliations (
                id bigserial PRIMARY KEY, payment_id bigint NOT NULL REFERENCES payments, order_id bigint NOT NULL REFERENCES orders,
                bank_account_id bigint NOT NULL REFERENCES bank_accounts, bank_tx_ref varchar(160) NOT NULL, amount bigint NOT NULL CHECK(amount>0),
                status varchar(12) NOT NULL DEFAULT 'OPEN' CHECK(status IN ('OPEN','APPROVED','REJECTED','APPLIED','RETURNED')),
                refund_transfer_ref varchar(160) UNIQUE, proof_file_id bigint UNIQUE REFERENCES stored_files, handled_by bigint NOT NULL REFERENCES users,
                reviewed_by bigint REFERENCES users CHECK(reviewed_by<>handled_by), reason_id bigint NOT NULL REFERENCES reason_codes,
                review_reason_id bigint REFERENCES reason_codes, review_note varchar(1000), decision_note varchar(1000), finalized_by bigint REFERENCES users,
                created_at timestamptz NOT NULL DEFAULT now(), UNIQUE(bank_account_id,bank_tx_ref),
                CHECK(status='OPEN' OR reviewed_by IS NOT NULL), CHECK(status<>'RETURNED' OR (refund_transfer_ref IS NOT NULL AND proof_file_id IS NOT NULL))
            );
            CREATE INDEX ON payment_reconciliations(status,created_at);
            ALTER TABLE payments ADD reconciliation_id bigint UNIQUE REFERENCES payment_reconciliations;
            CREATE UNIQUE INDEX allocated_invoice_once ON payment_allocations(invoice_id) WHERE applied_at IS NOT NULL;
            CREATE TRIGGER applied_amount_immutable BEFORE UPDATE OR DELETE ON payment_allocations FOR EACH ROW WHEN (OLD.applied_at IS NOT NULL) EXECUTE FUNCTION reject_foundation_rewrite();
            SQL);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::unprepared("LOCK TABLE payments,payment_reconciliations IN ACCESS EXCLUSIVE MODE;
            DO $$ BEGIN IF EXISTS(SELECT FROM payment_reconciliations) OR EXISTS(SELECT FROM payment_allocations WHERE applied_at IS NOT NULL) THEN RAISE EXCEPTION 'Cannot discard settlement history'; END IF; END $$;
            DROP TRIGGER applied_amount_immutable ON payment_allocations; DROP INDEX allocated_invoice_once;
            ALTER TABLE payments DROP reconciliation_id; DROP TABLE payment_reconciliations");
    }
};
