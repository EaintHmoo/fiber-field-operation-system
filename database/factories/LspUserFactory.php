<?php

namespace Database\Factories;

use App\Models\LspTeam;
use App\Models\LspUser;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;

/**
 * @extends Factory<LspUser>
 */
class LspUserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'lsp_team_id' => LspTeam::factory(),
            'lsp_id' => fn (array $attributes) => LspTeam::query()->whereKey($attributes['lsp_team_id'])->value('lsp_id'),
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'password' => static::$password ??= Hash::make('password'),
        ];
    }

    /**
     * Place the user in the given team (and that team's LSP).
     */
    public function forTeam(LspTeam $team): static
    {
        return $this->state(fn (array $attributes) => [
            'lsp_team_id' => $team->id,
            'lsp_id' => $team->lsp_id,
        ]);
    }
}
