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
            ALTER TABLE orders ADD request_order_id bigint UNIQUE,
                ADD FOREIGN KEY (request_order_id,user_id) REFERENCES request_orders(id,user_id),
                ADD CHECK(request_order_id IS NULL OR (selling_method='REQUEST_ORDER' AND campaign_id IS NULL));
            CREATE TRIGGER order_request_immutable BEFORE UPDATE OF request_order_id ON orders
                FOR EACH ROW WHEN (OLD.request_order_id IS NOT NULL) EXECUTE FUNCTION reject_foundation_rewrite();
            SQL);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::unprepared('DROP TRIGGER order_request_immutable ON orders; ALTER TABLE orders DROP request_order_id');
    }
};
