<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE shipment_items DROP CONSTRAINT shipment_items_shipment_id_order_item_id_key,
            ADD CONSTRAINT shipment_item_ranges_unique UNIQUE (shipment_id, order_item_id, coverage_start)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE shipment_items DROP CONSTRAINT shipment_item_ranges_unique,
            ADD CONSTRAINT shipment_items_shipment_id_order_item_id_key UNIQUE (shipment_id, order_item_id)');
    }
};
