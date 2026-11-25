<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE SEQUENCE preorder_number_seq OWNED BY preorder_campaigns.number;
            SELECT setval('preorder_number_seq',1+coalesce(max(substring(number from '^PO-[0-9]{8}-([0-9]{1,18})$')::bigint),0),false)
                FROM preorder_campaigns;
            CREATE INDEX preorder_campaign_window ON preorder_campaigns(starts_at) WHERE status='SCHEDULED';
            ALTER TABLE preorder_campaigns ADD first_received_at timestamptz;
            CREATE INDEX preorder_due_allocations ON preorder_allocations(expires_at,order_item_id) WHERE status='RESERVED';
            SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP INDEX preorder_campaign_window,preorder_due_allocations; ALTER TABLE preorder_campaigns DROP first_received_at; DROP SEQUENCE preorder_number_seq');
    }
};
