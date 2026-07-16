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
        Schema::create('creditors', function (Blueprint $table) {
            $table->id();
            $table->string('normalized_name')->index();
            $table->string('display_name');
            $table->string('ico', 20)->nullable()->index();
            $table->string('person_type', 40)->default('unknown');
            $table->string('legal_form_code', 20)->nullable();
            $table->string('nace_code', 20)->nullable();
            $table->timestampTz('ares_verified_at')->nullable();
            $table->json('ares_payload')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('creditors');
    }
};
