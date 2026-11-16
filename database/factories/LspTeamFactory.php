<?php

namespace Database\Factories;

use App\Models\Lsp;
use App\Models\LspTeam;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LspTeam>
 */
class LspTeamFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'lsp_id' => Lsp::factory(),
            'name' => 'Team '.fake()->unique()->bothify('?#'),
        ];
    }
}
