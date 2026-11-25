<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE shipping_quotes ADD carrier_label_snapshot varchar(120), ADD service_code_snapshot varchar(32);
            CREATE UNIQUE INDEX shipping_quotes_confirmed_cart ON shipping_quotes(cart_id) WHERE cart_id IS NOT NULL AND used_at IS NOT NULL;
            CREATE TRIGGER confirmed_cart_quote_immutable BEFORE UPDATE OR DELETE ON shipping_quotes
                FOR EACH ROW WHEN (OLD.cart_id IS NOT NULL AND OLD.used_at IS NOT NULL) EXECUTE FUNCTION reject_foundation_rewrite();
            SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER confirmed_cart_quote_immutable ON shipping_quotes; DROP INDEX shipping_quotes_confirmed_cart;
            ALTER TABLE shipping_quotes DROP carrier_label_snapshot, DROP service_code_snapshot');
    }
};
