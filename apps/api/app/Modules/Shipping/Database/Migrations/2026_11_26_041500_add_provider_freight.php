<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE courier_region_codes (
                code varchar(12) PRIMARY KEY, region_id bigint NOT NULL UNIQUE REFERENCES regions
            );
            CREATE TABLE courier_provider_masters (
                code varchar(32) PRIMARY KEY, courier_id bigint NOT NULL UNIQUE REFERENCES couriers,
                logo varchar(512), cod boolean NOT NULL
            );
            CREATE TABLE provider_freights (
                id bigserial PRIMARY KEY, quote_id varchar(160) NOT NULL,
                origin_code varchar(12) NOT NULL REFERENCES courier_region_codes,
                destination_code varchar(12) NOT NULL REFERENCES courier_region_codes,
                weight_g bigint NOT NULL CHECK(weight_g>0), service_id bigint NOT NULL REFERENCES courier_services,
                price bigint NOT NULL CHECK(price>=0), handling_fee bigint NOT NULL CHECK(handling_fee>=0),
                provider_details jsonb NOT NULL, fetched_at timestamptz NOT NULL DEFAULT now(),
                weight_kg numeric GENERATED ALWAYS AS (weight_g::numeric / 1000) STORED,
                amount bigint NOT NULL CHECK(amount BETWEEN 1 AND 1000000000000),
                eta_min_days integer NOT NULL CHECK(eta_min_days>=0), eta_max_days integer NOT NULL CHECK(eta_max_days>=eta_min_days),
                expires_at timestamptz NOT NULL
            );
            CREATE INDEX ON provider_freights(origin_code,destination_code,weight_g,expires_at);
            CREATE TRIGGER provider_freight_immutable BEFORE UPDATE ON provider_freights
                FOR EACH ROW EXECUTE FUNCTION reject_foundation_rewrite();
            CREATE TABLE provider_service_codes (
                courier_code varchar(32) NOT NULL REFERENCES courier_provider_masters,
                code varchar(32) NOT NULL, service_id bigint NOT NULL UNIQUE REFERENCES courier_services,
                PRIMARY KEY (courier_code,code)
            );
            ALTER TABLE shipping_quotes ALTER rate_id DROP NOT NULL, ADD provider_freight_id bigint REFERENCES provider_freights,
                ADD CONSTRAINT quote_one_rate CHECK(num_nonnulls(rate_id,provider_freight_id)=1);
            ALTER TABLE shipments ALTER rate_id DROP NOT NULL, ADD provider_freight_id bigint REFERENCES provider_freights,
                ADD CONSTRAINT shipment_one_rate CHECK(num_nonnulls(rate_id,provider_freight_id)=1);
            CREATE INDEX ON shipments(provider_freight_id) WHERE provider_freight_id IS NOT NULL;
            CREATE INDEX ON shipping_quotes(provider_freight_id) WHERE provider_freight_id IS NOT NULL;
            SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE shipments ALTER rate_id SET NOT NULL, DROP CONSTRAINT shipment_one_rate, DROP provider_freight_id;
            ALTER TABLE shipping_quotes ALTER rate_id SET NOT NULL, DROP CONSTRAINT quote_one_rate, DROP provider_freight_id;
            DROP TABLE provider_freights,provider_service_codes,courier_provider_masters,courier_region_codes;
            SQL);
    }
};
