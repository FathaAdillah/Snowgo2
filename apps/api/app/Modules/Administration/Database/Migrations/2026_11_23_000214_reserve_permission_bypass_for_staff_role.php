<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('roles', fn (Blueprint $table) => $table->boolean('bypasses_permissions')->default(false));
        DB::statement("ALTER TABLE roles ADD CONSTRAINT reserved_permission_bypass CHECK (NOT bypasses_permissions OR (guard_name = 'web' AND name = 'super_admin'))");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('roles', fn (Blueprint $table) => $table->dropColumn('bypasses_permissions'));
    }
};
