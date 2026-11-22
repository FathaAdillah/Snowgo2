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
        Schema::create('social_accounts', function (Blueprint $table) {
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 32);
            $table->string('subject');
            $table->primary(['provider', 'subject']);
            $table->unique(['user_id', 'provider']);
        });
        Schema::create('oauth_link_requests', function (Blueprint $table) {
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('subject');
            $table->string('email', 254);
            $table->char('token_hash', 64)->primary();
            $table->timestampTz('expires_at')->index();
            $table->timestampTz('used_at')->nullable();
            $table->index(['user_id', 'expires_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('oauth_link_requests');
        Schema::dropIfExists('social_accounts');
    }
};
