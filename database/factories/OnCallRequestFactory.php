<?php

namespace Database\Factories;

use App\Enums\JobStatus;
use App\Models\Lsp;
use App\Models\LspTeam;
use App\Models\OnCallRequest;
use App\Models\Status;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OnCallRequest>
 */
class OnCallRequestFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'status_id' => fn () => Status::query()->firstOrCreate(['name' => JobStatus::Pending->value])->id,
            'current_lsp_team_id' => LspTeam::factory(),
            'current_lsp_id' => fn (array $attributes) => LspTeam::query()->whereKey($attributes['current_lsp_team_id'])->value('lsp_id'),
            'appointment_date' => null,
        ];
    }

    /**
     * Assign the job to the given team (and that team's LSP).
     */
    public function forTeam(LspTeam $team): static
    {
        return $this->state(fn (array $attributes) => [
            'current_lsp_team_id' => $team->id,
            'current_lsp_id' => $team->lsp_id,
        ]);
    }

    /**
     * Job assigned to an LSP but not yet to a team (not bookable).
     */
    public function withoutTeam(): static
    {
        return $this->state(fn (array $attributes) => [
            'current_lsp_team_id' => null,
            'current_lsp_id' => Lsp::factory(),
        ]);
    }

    /**
     * Put the job in the named status, creating the status row if needed.
     */
    public function withStatus(JobStatus $status): static
    {
        return $this->state(fn (array $attributes) => [
            'status_id' => Status::query()->firstOrCreate(['name' => $status->value])->id,
        ]);
    }

    /**
     * Give the job an appointment at the given moment.
     */
    public function appointmentAt(DateTimeInterface|string $appointmentDate): static
    {
        return $this->state(fn (array $attributes) => [
            'appointment_date' => $appointmentDate,
        ]);
    }
}
