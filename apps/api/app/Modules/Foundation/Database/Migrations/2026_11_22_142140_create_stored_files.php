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
        Schema::create('stored_files', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->string('owner_type', 40);
            $table->bigInteger('owner_id');
            $table->string('collection', 40);
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->index('uploaded_by');
            $table->string('visibility', 8)->default('PRIVATE');
            $table->string('status', 16)->default('QUARANTINED');
            $table->string('disk', 40)->default('private');
            $table->string('object_key');
            $table->string('mime', 120);
            $table->bigInteger('size_bytes');
            $table->char('sha256', 64);
            $table->string('original_name')->nullable();
            $table->uuid('scan_token')->nullable();
            $table->timestampTz('scan_due_at', 6)->nullable()->useCurrent();
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();
            $table->unique(['disk', 'object_key']);
            $table->index(['owner_type', 'owner_id', 'collection', 'status']);
            $table->index(['status', 'scan_due_at']);
        });
        DB::statement("ALTER TABLE stored_files ADD CHECK (size_bytes > 0 AND owner_id > 0),
            ADD CHECK (status IN ('QUARANTINED','SCANNING','READY','REJECTED','DELETED')),
            ADD CHECK (visibility IN ('PUBLIC','PRIVATE') AND (visibility <> 'PUBLIC' OR (collection = 'catalog_media' AND status = 'READY'))),
            ADD CHECK ((status = 'SCANNING') = (scan_token IS NOT NULL))");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('stored_files');
    }
};
