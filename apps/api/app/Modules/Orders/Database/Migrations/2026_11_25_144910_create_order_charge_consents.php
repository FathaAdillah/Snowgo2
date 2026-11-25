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
            ALTER TABLE invoice_items ADD coverage_start integer CHECK(coverage_start IS NULL OR (coverage_start>=0 AND covered_qty IS NOT NULL));
            CREATE TABLE order_charge_consents (
                id bigserial PRIMARY KEY, public_id char(26) UNIQUE NOT NULL, order_id bigint NOT NULL REFERENCES orders,
                scope varchar(90) NOT NULL, revision integer NOT NULL CHECK(revision>0), UNIQUE(order_id,revision),
                lines jsonb NOT NULL CHECK(jsonb_array_length(lines)>0), amount bigint NOT NULL CHECK(amount>0),
                status varchar(16) NOT NULL CHECK(status IN ('PROPOSED','ACCEPTED','REJECTED','EXPIRED')),
                expires_at timestamptz NOT NULL, accepted_at timestamptz, invoice_id bigint UNIQUE REFERENCES invoices,
                quote_id bigint REFERENCES shipping_quotes, created_by bigint NOT NULL REFERENCES users,
                CHECK((status='ACCEPTED')=(invoice_id IS NOT NULL)), CHECK((status='ACCEPTED')=(accepted_at IS NOT NULL))
            );
            CREATE UNIQUE INDEX open_charge_scope ON order_charge_consents(order_id,scope) WHERE status='PROPOSED';
            CREATE INDEX ON order_charge_consents(status,expires_at);
            CREATE TRIGGER charge_frozen BEFORE UPDATE OF public_id,order_id,scope,revision,lines,amount,expires_at,quote_id,created_by
            ON order_charge_consents FOR EACH ROW EXECUTE FUNCTION reject_foundation_rewrite();
            SQL);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::unprepared("LOCK TABLE order_charge_consents,invoice_items IN ACCESS EXCLUSIVE MODE;
            DO $$ BEGIN IF EXISTS(SELECT FROM order_charge_consents) OR EXISTS(SELECT FROM invoice_items WHERE coverage_start IS NOT NULL) THEN RAISE EXCEPTION 'Cannot discard billing history'; END IF; END $$;
            DROP TABLE order_charge_consents; ALTER TABLE invoice_items DROP coverage_start");
    }
};
