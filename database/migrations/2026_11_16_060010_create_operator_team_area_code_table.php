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
        Schema::create('operator_team_area_code', function (Blueprint $table) {
            $table->foreignId('operator_team_id')->constrained('operator_teams')->cascadeOnDelete();
            $table->foreignId('area_code_id')->constrained('area_codes')->cascadeOnDelete();

            $table->primary(['operator_team_id', 'area_code_id']);
            $table->index('area_code_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('operator_team_area_code');
    }
};
