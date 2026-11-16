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
        Schema::create('installation_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('status_id')->constrained()->restrictOnDelete();
            $table->foreignId('current_lsp_id')->nullable()->constrained('lsps')->restrictOnDelete();
            $table->unsignedBigInteger('current_lsp_team_id')->nullable();
            $table->dateTime('appointment_date')->nullable()->comment('Stored in UTC; business day is derived in Asia/Yangon');
            $table->softDeletes();
            $table->timestamps();

            // When a team is assigned it must belong to current_lsp_id (a NULL team skips the check).
            $table->foreign(['current_lsp_team_id', 'current_lsp_id'])
                ->references(['id', 'lsp_id'])
                ->on('lsp_teams')
                ->restrictOnDelete();

            // Serves the per-team, per-day slot count (range scan on appointment_date).
            $table->index(['current_lsp_team_id', 'appointment_date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('installation_requests');
    }
};
