<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE returns (
                id bigserial PRIMARY KEY, number varchar(40) NOT NULL UNIQUE, order_id bigint NOT NULL REFERENCES orders,
                shipment_id bigint NOT NULL REFERENCES shipments, requested_by bigint NOT NULL REFERENCES users,
                reason_id bigint NOT NULL REFERENCES reason_codes, review_reason_id bigint REFERENCES reason_codes,
                status varchar(24) NOT NULL CHECK(status IN ('REQUESTED','UNDER_REVIEW','APPROVED','REJECTED','AWAITING_SHIPMENT','IN_TRANSIT','RECEIVED','INSPECTING','ACCEPTED','INSPECTION_FAILED','RESOLVED','CANCELLED')),
                note varchar(1000), instructions varchar(5000), customer_tracking_number varchar(120), received_at timestamptz, receipt_note varchar(1000), inspection_note varchar(5000),
                resolution varchar(24) CHECK(resolution IN ('REFUND','REPAIR','REPLACEMENT','SHIP_MISSING_ITEM','REJECTED')),
                outcome_note varchar(1000), resolved_at timestamptz, created_at timestamptz NOT NULL DEFAULT now()
            );
            CREATE INDEX ON returns(shipment_id,status); CREATE INDEX ON returns(order_id,status);
            CREATE TABLE return_items (id bigserial PRIMARY KEY, return_id bigint NOT NULL REFERENCES returns,
                shipment_item_id bigint NOT NULL REFERENCES shipment_items, qty integer NOT NULL CHECK(qty>0),
                accepted_qty integer NOT NULL DEFAULT 0 CHECK(accepted_qty BETWEEN 0 AND qty),
                disposition varchar(16) CHECK(disposition IN ('RESTOCK','DAMAGE','NO_RECEIPT')), UNIQUE(return_id,shipment_item_id));
            ALTER TABLE refunds ADD return_id bigint UNIQUE REFERENCES returns;
            ALTER TABLE shipments ADD replacement_return_id bigint REFERENCES returns;
            CREATE UNIQUE INDEX replacement_active ON shipments(replacement_return_id) WHERE status NOT IN ('CANCELLED','RETURNED');
            SQL);
        $this->movementTypes(true);
    }

    private function movementTypes(bool $enable): void
    {
        $in = $enable ? "'INITIAL','PURCHASE','ADJUSTMENT_IN','RETURN'" : "'INITIAL','PURCHASE','ADJUSTMENT_IN'";
        $out = $enable ? "'ADJUSTMENT_OUT','REPLACEMENT'" : "'ADJUSTMENT_OUT'";
        DB::unprepared("ALTER TABLE inventory_movements DROP CONSTRAINT inventory_movement_shape,
            ADD CONSTRAINT inventory_movement_shape CHECK (
                (reservation_id IS NULL AND reason_id IS NOT NULL AND reason_code IS NOT NULL AND
                ((type IN ($in) AND on_hand_delta>0) OR (type IN ($out) AND on_hand_delta<0))
                AND reserved_delta=0 AND committed_delta=0) OR
                (reservation_id IS NOT NULL AND reason_id IS NULL AND reason_code IS NULL AND CASE type
                WHEN 'RESERVATION' THEN on_hand_delta=0 AND reserved_delta>0 AND committed_delta=0
                WHEN 'RESERVATION_RELEASE' THEN on_hand_delta=0 AND reserved_delta<0 AND committed_delta=0
                WHEN 'COMMIT' THEN on_hand_delta=0 AND committed_delta>0 AND reserved_delta=-committed_delta
                WHEN 'COMMIT_RELEASE' THEN on_hand_delta=0 AND reserved_delta=0 AND committed_delta<0
                WHEN 'SALE' THEN on_hand_delta<0 AND reserved_delta=0 AND committed_delta=on_hand_delta ELSE false END))");
    }

    public function down(): void
    {
        DB::unprepared("LOCK TABLE returns IN ACCESS EXCLUSIVE MODE; DO $$ BEGIN IF EXISTS(SELECT FROM returns) THEN RAISE EXCEPTION 'Cannot discard return history'; END IF; END $$;
            ALTER TABLE shipments DROP replacement_return_id; ALTER TABLE refunds DROP return_id; DROP TABLE return_items,returns");
        $this->movementTypes(false);
    }
};
