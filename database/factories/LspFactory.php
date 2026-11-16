<?php

namespace Database\Factories;

use App\Models\Lsp;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Lsp>
 */
class LspFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->company(),
        ];
    }
}
