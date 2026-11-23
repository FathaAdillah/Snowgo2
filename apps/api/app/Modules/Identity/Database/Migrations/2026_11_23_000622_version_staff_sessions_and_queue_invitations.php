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
        Schema::table('users', fn (Blueprint $t) => $t->bigInteger('staff_session_version')->default(0));
        Schema::table('staff_invitations', function (Blueprint $t) {
            $t->text('delivery_ciphertext')->nullable();
            $t->timestampTz('created_at', 6)->useCurrent();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('staff_invitations', fn (Blueprint $t) => $t->dropColumn(['delivery_ciphertext', 'created_at']));
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn('staff_session_version'));
    }
};
