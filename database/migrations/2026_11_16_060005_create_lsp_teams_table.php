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
        Schema::create('lsp_teams', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lsp_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->timestamps();

            $table->unique(['lsp_id', 'name']);

            // Target for composite foreign keys that guarantee a team belongs to the referenced LSP.
            $table->unique(['id', 'lsp_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('lsp_teams');
    }
};
