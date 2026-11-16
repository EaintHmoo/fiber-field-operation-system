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
        Schema::create('lsp_team_daily_capacities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lsp_team_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('day_of_week')->comment('Carbon dayOfWeek in Asia/Yangon: 0 = Sunday ... 6 = Saturday');
            $table->unsignedInteger('max_slots')->default(8);
            $table->timestamps();

            // Prevents duplicate default rows under concurrent insertOrIgnore; also the lockForUpdate lookup key.
            $table->unique(['lsp_team_id', 'day_of_week']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('lsp_team_daily_capacities');
    }
};
