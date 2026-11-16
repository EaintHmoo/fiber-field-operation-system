<?php

namespace Tests\Feature\Repositories\Eloquent;

use App\Enums\JobStatus;
use App\Enums\RequestType;
use App\Models\InstallationRequest;
use App\Models\LspTeam;
use App\Models\OnCallRequest;
use App\Repositories\Contracts\AppointmentRepositoryInterface;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class EloquentAppointmentRepositoryTest extends TestCase
{
    use LazilyRefreshDatabase;

    private const string YANGON = 'Asia/Yangon';

    public function test_counts_installation_and_on_call_jobs_together(): void
    {
        $team = LspTeam::factory()->create();
        InstallationRequest::factory()->forTeam($team)->appointmentAt('2026-04-02 04:00:00')->create();
        OnCallRequest::factory()->forTeam($team)->appointmentAt('2026-04-02 05:00:00')->create();

        $this->assertSame(2, $this->countYangonDay($team, '2026-04-02'));
    }

    public function test_does_not_count_soft_deleted_jobs(): void
    {
        $team = LspTeam::factory()->create();
        InstallationRequest::factory()->forTeam($team)->appointmentAt('2026-04-02 04:00:00')->create()->delete();
        OnCallRequest::factory()->forTeam($team)->appointmentAt('2026-04-02 04:00:00')->create()->delete();

        $this->assertSame(0, $this->countYangonDay($team, '2026-04-02'));
    }

    #[DataProvider('nonSlotConsumingStatuses')]
    public function test_does_not_count_jobs_in_non_slot_consuming_status(JobStatus $status): void
    {
        $team = LspTeam::factory()->create();
        InstallationRequest::factory()->forTeam($team)->withStatus($status)->appointmentAt('2026-04-02 04:00:00')->create();
        OnCallRequest::factory()->forTeam($team)->withStatus($status)->appointmentAt('2026-04-02 04:00:00')->create();

        $this->assertSame(0, $this->countYangonDay($team, '2026-04-02'));
    }

    #[DataProvider('slotConsumingStatuses')]
    public function test_counts_jobs_in_slot_consuming_status(JobStatus $status): void
    {
        $team = LspTeam::factory()->create();
        InstallationRequest::factory()->forTeam($team)->withStatus($status)->appointmentAt('2026-04-02 04:00:00')->create();

        $this->assertSame(1, $this->countYangonDay($team, '2026-04-02'));
    }

    public function test_does_not_count_other_teams_jobs(): void
    {
        $team = LspTeam::factory()->create();
        $otherTeam = LspTeam::factory()->for($team->lsp)->create();
        InstallationRequest::factory()->forTeam($otherTeam)->appointmentAt('2026-04-02 04:00:00')->create();

        $this->assertSame(0, $this->countYangonDay($team, '2026-04-02'));
    }

    public function test_counts_yangon_just_after_midnight_on_that_yangon_day_not_the_utc_day(): void
    {
        $team = LspTeam::factory()->create();
        // 2026-04-02 00:30 Asia/Yangon (UTC+06:30) is stored as 2026-04-01 18:00 UTC.
        InstallationRequest::factory()->forTeam($team)->appointmentAt('2026-04-01 18:00:00')->create();

        $this->assertSame(1, $this->countYangonDay($team, '2026-04-02'));
        $this->assertSame(0, $this->countYangonDay($team, '2026-04-01'));
    }

    public function test_range_includes_start_and_excludes_end(): void
    {
        $team = LspTeam::factory()->create();
        // Yangon 2026-04-02 00:00 and 2026-04-03 00:00 in UTC.
        InstallationRequest::factory()->forTeam($team)->appointmentAt('2026-04-01 17:30:00')->create();
        InstallationRequest::factory()->forTeam($team)->appointmentAt('2026-04-02 17:30:00')->create();

        $this->assertSame(1, $this->countYangonDay($team, '2026-04-02'));
    }

    public function test_update_appointment_date_stores_yangon_time_as_utc(): void
    {
        $job = InstallationRequest::factory()->create();

        $this->repository()->updateAppointmentDate($job, CarbonImmutable::parse('2026-04-02 00:30:00', self::YANGON));

        $this->assertDatabaseHas('installation_requests', [
            'id' => $job->id,
            'appointment_date' => '2026-04-01 18:00:00',
        ]);
    }

    public function test_find_returns_job_from_table_matching_request_type(): void
    {
        $installation = InstallationRequest::factory()->create();
        $onCall = OnCallRequest::factory()->create();

        $this->assertTrue($installation->is($this->repository()->find(RequestType::Installation, $installation->id)));
        $this->assertTrue($onCall->is($this->repository()->find(RequestType::OnCall, $onCall->id)));
    }

    public function test_find_rejects_soft_deleted_job(): void
    {
        $job = InstallationRequest::factory()->create();
        $job->delete();

        $this->expectException(ModelNotFoundException::class);

        $this->repository()->find(RequestType::Installation, $job->id);
    }

    /**
     * @return array<string, array{JobStatus}>
     */
    public static function nonSlotConsumingStatuses(): array
    {
        return collect(JobStatus::nonSlotConsuming())
            ->mapWithKeys(fn (JobStatus $status): array => [$status->value => [$status]])
            ->all();
    }

    /**
     * @return array<string, array{JobStatus}>
     */
    public static function slotConsumingStatuses(): array
    {
        return collect(JobStatus::cases())
            ->filter(fn (JobStatus $status): bool => $status->consumesSlot())
            ->mapWithKeys(fn (JobStatus $status): array => [$status->value => [$status]])
            ->all();
    }

    private function countYangonDay(LspTeam $team, string $date): int
    {
        $start = CarbonImmutable::parse($date, self::YANGON)->startOfDay();

        return $this->repository()->countUsedSlots($team->id, $start, $start->addDay());
    }

    private function repository(): AppointmentRepositoryInterface
    {
        return $this->app->make(AppointmentRepositoryInterface::class);
    }
}
