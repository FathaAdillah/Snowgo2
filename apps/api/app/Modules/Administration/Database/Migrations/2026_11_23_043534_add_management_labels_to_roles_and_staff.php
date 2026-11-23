<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('roles', function (Blueprint $table) {
            $table->string('display_name', 100)->nullable();
            $table->string('description', 500)->nullable();
            $table->unsignedInteger('revision')->default(1);
        });
        Schema::table('users', fn (Blueprint $table) => $table->string('staff_display_name', 100)->nullable());
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('staff_display_name'));
        Schema::table('roles', fn (Blueprint $table) => $table->dropColumn(['display_name', 'description', 'revision']));
    }
};
