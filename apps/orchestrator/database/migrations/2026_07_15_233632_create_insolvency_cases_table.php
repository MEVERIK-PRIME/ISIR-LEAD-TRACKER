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
        Schema::create('insolvency_cases', function (Blueprint $table) {
            $table->id();
            $table->string('court_file_reference', 120)->unique();
            $table->string('debtor_name');
            $table->string('debtor_ico', 20)->nullable()->index();
            $table->string('proceeding_status', 50)->nullable();
            $table->string('source_section', 10)->default('B');
            $table->string('last_isir_event_id', 64)->nullable()->index();
            $table->timestampTz('last_event_at')->nullable();
            $table->timestampTz('last_document_published_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('insolvency_cases');
    }
};
