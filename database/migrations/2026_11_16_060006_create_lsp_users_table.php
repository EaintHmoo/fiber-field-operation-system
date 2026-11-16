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
        Schema::create('lsp_users', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lsp_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('lsp_team_id');
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->timestamps();

            $table->foreign(['lsp_team_id', 'lsp_id'])
                ->references(['id', 'lsp_id'])
                ->on('lsp_teams')
                ->restrictOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('lsp_users');
    }
};
