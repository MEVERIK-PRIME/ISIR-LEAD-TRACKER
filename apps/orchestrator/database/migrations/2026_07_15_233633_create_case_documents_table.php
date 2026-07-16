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
        Schema::create('case_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('insolvency_case_id')->constrained()->cascadeOnDelete();
            $table->string('isir_document_id', 64)->unique();
            $table->string('isir_event_id', 64)->index();
            $table->string('section', 10)->index();
            $table->string('event_label');
            $table->string('document_type', 80)->nullable();
            $table->text('document_url');
            $table->string('source_provider', 40)->default('isir_public_ws');
            $table->string('download_status', 40)->default('pending');
            $table->string('parse_status', 40)->default('pending');
            $table->string('checksum', 64)->nullable();
            $table->string('content_hash', 64)->nullable();
            $table->timestampTz('published_at')->nullable();
            $table->timestampTz('fetched_at')->nullable();
            $table->timestampTz('parsed_at')->nullable();
            $table->json('payload')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('case_documents');
    }
};
