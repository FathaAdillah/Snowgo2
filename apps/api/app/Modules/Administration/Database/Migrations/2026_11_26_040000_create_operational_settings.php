<?php

use Database\Seeders\OperationalDefaultsSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared('CREATE TABLE settings (id bigserial PRIMARY KEY, key varchar(80) UNIQUE NOT NULL,
            value integer NOT NULL, revision integer NOT NULL DEFAULT 0, minimum integer NOT NULL, maximum integer NOT NULL,
            CHECK (value BETWEEN minimum AND maximum))');
        (new OperationalDefaultsSeeder)->run();
    }

    public function down(): void
    {
        DB::unprepared("LOCK TABLE settings IN ACCESS EXCLUSIVE MODE; DO $$ BEGIN IF EXISTS(SELECT FROM settings WHERE revision>0) THEN RAISE EXCEPTION 'Cannot discard modified settings'; END IF; END $$; DROP TABLE settings");
    }
};
