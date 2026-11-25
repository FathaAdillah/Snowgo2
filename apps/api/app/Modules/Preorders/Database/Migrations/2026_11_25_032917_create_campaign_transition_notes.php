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
            CREATE TABLE preorder_transition_notes (
                history_id bigint PRIMARY KEY REFERENCES status_histories,
                note varchar(1000) NOT NULL
            );
            CREATE TRIGGER preorder_transition_note_immutable BEFORE UPDATE OR DELETE ON preorder_transition_notes
                FOR EACH ROW EXECUTE FUNCTION reject_foundation_rewrite();
            SQL);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('DROP TABLE preorder_transition_notes');
    }
};
