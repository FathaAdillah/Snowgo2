<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared("
            CREATE TABLE content_entries (
                id bigserial PRIMARY KEY, slug varchar(160) NOT NULL UNIQUE,
                type varchar(16) NOT NULL CHECK(type IN ('PAGE','FAQ','BANNER','FEATURED')),
                title varchar(160) NOT NULL, body text NOT NULL,
                status varchar(12) NOT NULL CHECK(status IN ('DRAFT','PUBLISHED','ARCHIVED')),
                publish_at timestamptz, version integer NOT NULL DEFAULT 1, sort_order integer NOT NULL DEFAULT 0,
                media_id char(26) REFERENCES stored_files(public_id)
            );
            CREATE TABLE content_publications (
                entry_id bigint NOT NULL REFERENCES content_entries, version integer NOT NULL, effective_at timestamptz NOT NULL,
                snapshot jsonb NOT NULL, PRIMARY KEY(entry_id,version)
            );
            CREATE INDEX ON content_publications(entry_id,effective_at DESC)
        ");
    }

    public function down(): void
    {
        DB::unprepared('DROP TABLE content_publications, content_entries');
    }
};
