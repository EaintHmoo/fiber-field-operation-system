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
        Schema::create('appointment_booking_logs', function (Blueprint $table) {
            $table->id();

            // Polymorphic-style reference to installation_requests or on_call_requests; validated in application code.
            $table->enum('request_type', ['installation', 'oncall']);
            $table->unsignedBigInteger('request_id');

            $table->foreignId('lsp_team_id')->constrained()->restrictOnDelete();
            $table->date('appointment_date')->comment('Asia/Yangon calendar date booked');
            $table->enum('action', ['book', 'reschedule', 'override']);
            $table->text('reason')->nullable();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('actor_lsp_user_id')->nullable()->constrained('lsp_users')->nullOnDelete();
            $table->timestamps();

            $table->index(['request_type', 'request_id']);
            $table->index(['lsp_team_id', 'appointment_date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('appointment_booking_logs');
    }
};
