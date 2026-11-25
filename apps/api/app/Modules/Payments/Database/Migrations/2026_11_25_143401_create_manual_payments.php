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
            CREATE TABLE bank_accounts (
                id bigserial PRIMARY KEY, code varchar(32) UNIQUE NOT NULL, bank_code varchar(24) NOT NULL,
                account_ref_ciphertext text NOT NULL, account_ref_hash char(64) NOT NULL, masked_number varchar(32) NOT NULL,
                holder_name varchar(160) NOT NULL, active boolean NOT NULL DEFAULT true, deleted_at timestamptz, UNIQUE(bank_code,account_ref_hash)
            );
            CREATE TABLE payments (
                id bigserial PRIMARY KEY, number varchar(40) UNIQUE NOT NULL, user_id bigint NOT NULL REFERENCES users,
                bank_account_id bigint NOT NULL REFERENCES bank_accounts, instruction_snapshot_ciphertext text NOT NULL,
                provider varchar(40) NOT NULL DEFAULT 'MANUAL', amount bigint NOT NULL CHECK(amount>0), currency char(3) NOT NULL DEFAULT 'IDR',
                status varchar(24) NOT NULL DEFAULT 'UNPAID' CHECK(status IN ('UNPAID','WAITING_CONFIRMATION','UNDER_REVIEW','EXPIRED','CANCELLED','REJECTED','PAID','PARTIALLY_REFUNDED','REFUNDED')),
                expires_at timestamptz NOT NULL, submitted_at timestamptz, verified_at timestamptz, verified_by bigint REFERENCES users,
                claim_by bigint REFERENCES users, claim_until timestamptz, rejection_reason_id bigint REFERENCES reason_codes,
                received_amount bigint CHECK(received_amount>0), bank_tx_ref varchar(160), created_at timestamptz NOT NULL DEFAULT now(),
                external_ref varchar(160), UNIQUE(provider,external_ref),
                CHECK((claim_by IS NULL)=(claim_until IS NULL)), UNIQUE(bank_account_id,bank_tx_ref)
            );
            CREATE INDEX ON payments(user_id,status); CREATE INDEX ON payments(status,expires_at);
            CREATE TABLE payment_allocations (
                id bigserial PRIMARY KEY, payment_id bigint NOT NULL REFERENCES payments, invoice_id bigint NOT NULL REFERENCES invoices,
                amount bigint NOT NULL CHECK(amount>0), applied_at timestamptz, UNIQUE(payment_id,invoice_id)
            );
            CREATE INDEX ON payment_allocations(invoice_id,applied_at);
            CREATE TABLE payment_proofs (
                id bigserial PRIMARY KEY, payment_id bigint NOT NULL REFERENCES payments, file_id bigint NOT NULL UNIQUE REFERENCES stored_files,
                submitted_by bigint NOT NULL REFERENCES users, created_at timestamptz NOT NULL DEFAULT now()
            );
            CREATE INDEX ON payment_proofs(payment_id,created_at);
            CREATE TRIGGER proof_immutable BEFORE UPDATE OR DELETE ON payment_proofs FOR EACH ROW EXECUTE FUNCTION reject_foundation_rewrite();
            CREATE TABLE payment_hold_extensions(order_id bigint PRIMARY KEY REFERENCES orders, proof_id bigint NOT NULL REFERENCES payment_proofs,
                expires_at timestamptz NOT NULL);
            SQL);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::unprepared("LOCK TABLE payments,bank_accounts IN ACCESS EXCLUSIVE MODE;
            DO $$ BEGIN IF EXISTS(SELECT FROM payments) OR EXISTS(SELECT FROM bank_accounts) THEN RAISE EXCEPTION 'Cannot discard payment records'; END IF; END $$;
            DROP TABLE payment_hold_extensions,payment_proofs,payment_allocations,payments,bank_accounts");
    }
};
