<?php

namespace Database\Factories;

use App\Models\LspTeam;
use App\Models\LspTeamDailyCapacity;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LspTeamDailyCapacity>
 */
class LspTeamDailyCapacityFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'lsp_team_id' => LspTeam::factory(),
            'day_of_week' => fake()->numberBetween(0, 6),
            'max_slots' => LspTeamDailyCapacity::DEFAULT_MAX_SLOTS,
        ];
    }
}
