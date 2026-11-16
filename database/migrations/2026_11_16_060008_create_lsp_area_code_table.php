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
        Schema::create('lsp_area_code', function (Blueprint $table) {
            $table->foreignId('lsp_id')->constrained('lsps')->cascadeOnDelete();
            $table->foreignId('area_code_id')->constrained('area_codes')->cascadeOnDelete();

            $table->primary(['lsp_id', 'area_code_id']);
            $table->index('area_code_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('lsp_area_code');
    }
};
