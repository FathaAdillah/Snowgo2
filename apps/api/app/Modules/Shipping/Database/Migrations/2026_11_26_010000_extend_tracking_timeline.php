<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE shipments DROP CONSTRAINT shipments_status_check,
                ADD CONSTRAINT shipments_status_check CHECK(status IN ('PENDING','PICKED_UP','CANCELLED','IN_TRANSIT','OUT_FOR_DELIVERY','FAILED','RETURNED','DELIVERED')),
                ADD delivered_at timestamptz;
            ALTER TABLE tracking_events ADD applied boolean NOT NULL DEFAULT true,
                ADD source varchar(12) NOT NULL DEFAULT 'MANUAL',
                ADD raw_status varchar(80), ADD note varchar(1000);
            CREATE INDEX tracking_latest ON tracking_events(shipment_id, occurred_at DESC) WHERE applied;
            CREATE INDEX shipment_deliveries ON shipments(order_id, delivered_at DESC) WHERE delivered_at IS NOT NULL;
            CREATE TRIGGER tracking_immutable BEFORE UPDATE OR DELETE ON tracking_events FOR EACH ROW EXECUTE FUNCTION reject_foundation_rewrite();
            SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            LOCK TABLE tracking_events IN ACCESS EXCLUSIVE MODE;
            DO $$ BEGIN IF EXISTS(SELECT FROM tracking_events WHERE event_key!='dispatch') THEN RAISE EXCEPTION 'Cannot discard tracking history'; END IF; END $$;
            DROP INDEX tracking_latest, shipment_deliveries;
            DROP TRIGGER tracking_immutable ON tracking_events;
            ALTER TABLE tracking_events DROP applied,DROP source,DROP raw_status,DROP note;
            ALTER TABLE shipments DROP delivered_at, DROP CONSTRAINT shipments_status_check,
                ADD CONSTRAINT shipments_status_check CHECK(status IN ('PENDING','PICKED_UP','CANCELLED'));
            SQL);
    }
};
