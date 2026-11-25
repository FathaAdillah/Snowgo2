<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'

 CREATE TABLE shipments(id bigserial PRIMARY KEY, number varchar(40) UNIQUE NOT NULL,order_id bigint NOT NULL REFERENCES orders,warehouse_id bigint NOT NULL REFERENCES warehouses,
 status varchar(16) NOT NULL DEFAULT 'PENDING' CHECK(status IN ('PENDING','PICKED_UP','CANCELLED')),packing_status varchar(20) NOT NULL DEFAULT 'PENDING' CHECK(packing_status IN ('PENDING','PACKING','PACKED','READY_FOR_DISPATCH')),
 weight_g bigint NOT NULL CHECK(weight_g>=0), service_id bigint NOT NULL REFERENCES courier_services,rate_id bigint NOT NULL REFERENCES shipping_rates,carrier_id bigint NOT NULL REFERENCES couriers,carrier_label varchar(120) NOT NULL,service_code varchar(32) NOT NULL,shipping_amount bigint NOT NULL CHECK(shipping_amount>=0),eta_min_days int NOT NULL,eta_max_days int NOT NULL,tracking_number varchar(120),shipped_at timestamptz,created_at timestamptz DEFAULT now(),UNIQUE(carrier_id,tracking_number));
 CREATE SEQUENCE IF NOT EXISTS shipment_number_seq;
 ALTER SEQUENCE shipment_number_seq OWNED BY shipments.number;
 CREATE INDEX ON shipments(order_id,status);
 CREATE TABLE shipment_items(id bigserial PRIMARY KEY,public_id char(26) NOT NULL UNIQUE, shipment_id bigint NOT NULL REFERENCES shipments,order_item_id bigint NOT NULL REFERENCES order_items,qty integer NOT NULL CHECK(qty>0),coverage_start integer NOT NULL CHECK(coverage_start>=0), UNIQUE(shipment_id,order_item_id));
 CREATE INDEX ON shipment_items(order_item_id);
 CREATE TABLE tracking_events(id bigserial PRIMARY KEY,shipment_id bigint NOT NULL REFERENCES shipments,event_key varchar(80) NOT NULL, normalized_status varchar(32) NOT NULL, occurred_at timestamptz NOT NULL DEFAULT now(), UNIQUE(shipment_id,event_key));
 ALTER TABLE shipping_quotes ADD shipment_id bigint REFERENCES shipments; CREATE INDEX ON shipping_quotes(shipment_id);
 CREATE TRIGGER package_lines_immutable BEFORE UPDATE OR DELETE ON shipment_items FOR EACH ROW EXECUTE FUNCTION reject_foundation_rewrite();
 SQL);
    }

    public function down(): void
    {
        DB::unprepared("LOCK TABLE shipments IN ACCESS EXCLUSIVE MODE; DO $$ BEGIN IF EXISTS(SELECT FROM shipments) THEN RAISE EXCEPTION 'Cannot discard shipment history'; END IF; END $$; ALTER TABLE shipping_quotes DROP shipment_id; DROP TABLE tracking_events,shipment_items,shipments");
    }
};
