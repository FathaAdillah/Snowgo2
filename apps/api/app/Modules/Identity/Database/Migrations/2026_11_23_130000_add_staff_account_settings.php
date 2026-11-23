<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', fn (Blueprint $t) => $t->boolean('staff_password_only')->default(false));
        Schema::create('staff_email_changes', function (Blueprint $t) {
            $t->foreignId('user_id')->primary()->constrained()->cascadeOnDelete();
            $t->string('email', 254);
            $t->bigInteger('session_version');
            $t->char('token_hash', 64);
            $t->text('delivery_ciphertext')->nullable();
            $t->timestampTz('created_at', 6);
            $t->timestampTz('expires_at')->index();
        });
    }

    public function down(): void
    {
        DB::table('users')->where('staff_password_only', true)->increment('staff_session_version');
        Schema::dropIfExists('staff_email_changes');
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn('staff_password_only'));
    }
};
