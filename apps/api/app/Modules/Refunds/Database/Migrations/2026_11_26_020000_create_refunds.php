<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE refunds (
                id bigserial PRIMARY KEY, number varchar(40) NOT NULL UNIQUE, order_id bigint NOT NULL REFERENCES orders,
                requested_by bigint NOT NULL REFERENCES users, reason_id bigint NOT NULL REFERENCES reason_codes,
                kind varchar(8) NOT NULL CHECK(kind IN ('FULL','PARTIAL','ITEM')), requested_amount bigint NOT NULL CHECK(requested_amount>0),
                approved_amount bigint CHECK(approved_amount BETWEEN 1 AND requested_amount), cancel_order boolean NOT NULL,
                note varchar(1000), status varchar(20) NOT NULL CHECK(status IN ('REQUESTED','UNDER_REVIEW','APPROVED','PROCESSING','FAILED','REJECTED','COMPLETED','CANCELLED')),
                reviewed_by bigint REFERENCES users, executor_id bigint REFERENCES users CHECK(executor_id<>reviewed_by),
                decision_note varchar(1000), decision_reason_id bigint REFERENCES reason_codes, completed_at timestamptz, created_at timestamptz NOT NULL DEFAULT now()
            );
            CREATE INDEX ON refunds(order_id,status);
            CREATE TABLE refund_items (id bigserial PRIMARY KEY, refund_id bigint NOT NULL REFERENCES refunds,order_item_id bigint NOT NULL REFERENCES order_items,
                qty integer NOT NULL CHECK(qty>0), requested_amount bigint NOT NULL CHECK(requested_amount>0), approved_amount bigint CHECK(approved_amount BETWEEN 0 AND requested_amount),
                UNIQUE(refund_id,order_item_id));
            CREATE TABLE refund_transfers (id bigserial PRIMARY KEY, refund_id bigint UNIQUE NOT NULL REFERENCES refunds, transfer_ref varchar(160) UNIQUE NOT NULL,
                confirmed_by bigint NOT NULL REFERENCES users, proof_file_id bigint UNIQUE NOT NULL REFERENCES stored_files, transferred_at timestamptz NOT NULL);
            CREATE TABLE refund_transactions (transfer_id bigint NOT NULL REFERENCES refund_transfers,
                payment_allocation_id bigint NOT NULL REFERENCES payment_allocations, amount bigint NOT NULL CHECK(amount>0), PRIMARY KEY(transfer_id,payment_allocation_id));
            CREATE INDEX ON refund_transactions(payment_allocation_id);
            CREATE TRIGGER refund_transfers_immutable BEFORE UPDATE OR DELETE ON refund_transfers FOR EACH ROW EXECUTE FUNCTION reject_foundation_rewrite();
            CREATE TRIGGER refund_transactions_immutable BEFORE UPDATE OR DELETE ON refund_transactions FOR EACH ROW EXECUTE FUNCTION reject_foundation_rewrite();
            SQL);
    }

    public function down(): void
    {
        DB::unprepared("LOCK TABLE refunds IN ACCESS EXCLUSIVE MODE; DO $$ BEGIN IF EXISTS(SELECT FROM refunds) THEN RAISE EXCEPTION 'Cannot discard refund records'; END IF; END $$; DROP TABLE refund_transactions,refund_transfers,refund_items,refunds");
    }
};
