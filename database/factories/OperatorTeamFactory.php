<?php

namespace Database\Factories;

use App\Models\OperatorTeam;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OperatorTeam>
 */
class OperatorTeamFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->city().' Operations',
        ];
    }
}
