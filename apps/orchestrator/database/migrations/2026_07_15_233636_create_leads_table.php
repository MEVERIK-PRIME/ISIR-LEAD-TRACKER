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
        Schema::create('leads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('insolvency_case_id')->constrained()->cascadeOnDelete();
            $table->foreignId('creditor_id')->constrained()->cascadeOnDelete();
            $table->string('lead_key')->unique();
            $table->string('sheet_row_id', 64)->nullable()->index();
            $table->string('status', 40)->default('new')->index();
            $table->string('status_source', 40)->default('system');
            $table->string('qualification_status', 40)->default('pending')->index();
            $table->text('qualification_reason')->nullable();
            $table->decimal('claim_amount_total_czk', 15, 2)->default(0);
            $table->decimal('secured_claim_amount_czk', 15, 2)->default(0);
            $table->decimal('unsecured_claim_amount_czk', 15, 2)->default(0);
            $table->string('primary_claim_type', 40)->nullable();
            $table->timestampTz('last_qualified_at')->nullable();
            $table->timestampTz('last_synced_to_sheet_at')->nullable();
            $table->timestampTz('last_sheet_import_at')->nullable();
            $table->timestampTz('last_material_change_at')->nullable();
            $table->unsignedInteger('business_state_version')->default(1);
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['insolvency_case_id', 'creditor_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('leads');
    }
};
