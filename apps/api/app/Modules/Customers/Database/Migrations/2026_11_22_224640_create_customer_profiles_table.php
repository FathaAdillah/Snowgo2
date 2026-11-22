<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * DB-CUS-001 (docs/04-DATABASE-SCHEMA.md).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE customer_profiles (
                user_id bigint PRIMARY KEY REFERENCES users ON DELETE CASCADE,
                display_name varchar(120) NOT NULL, phone varchar(32),
                marketing_opt_in boolean NOT NULL DEFAULT false,
                created_at timestamptz NOT NULL DEFAULT now(), updated_at timestamptz NOT NULL DEFAULT now()
            );
            SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TABLE customer_profiles');
    }
};
