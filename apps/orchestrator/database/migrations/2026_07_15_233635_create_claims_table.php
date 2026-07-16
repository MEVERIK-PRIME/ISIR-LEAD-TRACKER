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
        Schema::create('claims', function (Blueprint $table) {
            $table->id();
            $table->foreignId('insolvency_case_id')->constrained()->cascadeOnDelete();
            $table->foreignId('case_document_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('creditor_id')->constrained()->cascadeOnDelete();
            $table->string('claim_fingerprint')->nullable()->unique();
            $table->string('source_reference', 120)->nullable();
            $table->string('claim_type', 40)->index();
            $table->decimal('amount_czk', 15, 2)->index();
            $table->string('currency', 3)->default('CZK');
            $table->string('priority_label', 120)->nullable();
            $table->boolean('secured')->default(false);
            $table->text('raw_excerpt')->nullable();
            $table->timestampTz('extracted_at')->nullable();
            $table->json('qualification_snapshot')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['insolvency_case_id', 'creditor_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('claims');
    }
};
