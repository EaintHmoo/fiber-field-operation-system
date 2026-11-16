<?php

namespace Tests\Feature\Services;

use App\Enums\BookingAction;
use App\Enums\JobStatus;
use App\Enums\PermissionName;
use App\Exceptions\CapacityExceededException;
use App\Exceptions\MissingTeamAssignmentException;
use App\Models\AppointmentBookingLog;
use App\Models\InstallationRequest;
use App\Models\LspTeam;
use App\Models\LspTeamDailyCapacity;
use App\Models\LspUser;
use App\Models\OnCallRequest;
use App\Models\Permission;
use App\Models\User;
use App\Repositories\Contracts\BookingLogRepositoryInterface;
use App\Services\AppointmentCapacityService;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Tests\TestCase;

class AppointmentCapacityServiceTest extends TestCase
{
    use LazilyRefreshDatabase;

    private const string THURSDAY = '2026-04-02';

    public function test_booking_under_capacity_stores_utc_date_logs_book_and_uses_one_slot(): void
    {
        $team = LspTeam::factory()->create();
        $job = InstallationRequest::factory()->forTeam($team)->create();
        $actor = LspUser::factory()->forTeam($team)->create();

        $result = $this->service()->book($job, $this->yangon(self::THURSDAY.' 10:00:00'), $actor);

        $this->assertSame(BookingAction::Book, $result->action);
        $this->assertSame(1, $result->capacity->usedSlots);
        $this->assertDatabaseHas('installation_requests', ['id' => $job->id, 'appointment_date' => '2026-04-02 03:30:00']);
        $this->assertDatabaseHas('appointment_booking_logs', [
            'request_type' => 'installation',
            'request_id' => $job->id,
            'lsp_team_id' => $team->id,
            'appointment_date' => self::THURSDAY,
            'action' => 'book',
            'actor_lsp_user_id' => $actor->id,
        ]);
        $this->assertSame(1, $this->usedSlots($team, self::THURSDAY));
        $this->assertDatabaseHas('lsp_team_daily_capacities', ['lsp_team_id' => $team->id, 'day_of_week' => 4, 'max_slots' => 8]);
    }

    public function test_rebooking_same_job_on_same_yangon_day_is_idempotent(): void
    {
        $team = LspTeam::factory()->create();
        $job = InstallationRequest::factory()->forTeam($team)->create();
        $actor = LspUser::factory()->forTeam($team)->create();
        $this->service()->book($job, $this->yangon(self::THURSDAY.' 10:00:00'), $actor);

        $result = $this->service()->book($job, $this->yangon(self::THURSDAY.' 15:00:00'), $actor);

        $this->assertTrue($result->wasIdempotent());
        $this->assertSame(1, $this->usedSlots($team, self::THURSDAY));
        $this->assertDatabaseCount('appointment_booking_logs', 1);
        $this->assertDatabaseHas('installation_requests', ['id' => $job->id, 'appointment_date' => '2026-04-02 08:30:00']);
    }

    public function test_rebooking_same_day_succeeds_even_when_day_is_full(): void
    {
        $team = $this->teamWithThursdayCapacity(1);
        $job = InstallationRequest::factory()->forTeam($team)->appointmentAt($this->yangon(self::THURSDAY.' 10:00:00'))->create();

        $result = $this->service()->book($job, $this->yangon(self::THURSDAY.' 11:00:00'), LspUser::factory()->forTeam($team)->create());

        $this->assertTrue($result->wasIdempotent());
    }

    public function test_booking_on_full_day_throws_with_zero_remaining_and_leaves_job_unchanged(): void
    {
        $team = $this->teamWithThursdayCapacity(2);
        InstallationRequest::factory()->count(2)->forTeam($team)->appointmentAt($this->yangon(self::THURSDAY.' 09:00:00'))->create();
        $job = InstallationRequest::factory()->forTeam($team)->create();

        try {
            $this->service()->book($job, $this->yangon(self::THURSDAY.' 10:00:00'), LspUser::factory()->forTeam($team)->create());
            $this->fail('Expected CapacityExceededException.');
        } catch (CapacityExceededException $exception) {
            $this->assertSame(0, $exception->capacity->remainingSlots());
            $this->assertSame(422, $exception->render()->getStatusCode());
            $this->assertSame(0, $exception->render()->getData(true)['remaining_slots']);
        }

        $this->assertNull($job->fresh()->appointment_date);
        $this->assertDatabaseCount('appointment_booking_logs', 0);
    }

    public function test_ninth_booking_is_rejected_by_default_capacity_of_eight(): void
    {
        $team = LspTeam::factory()->create();
        InstallationRequest::factory()->count(8)->forTeam($team)->appointmentAt($this->yangon(self::THURSDAY.' 09:00:00'))->create();
        $job = InstallationRequest::factory()->forTeam($team)->create();

        $this->assertThrows(
            fn () => $this->service()->book($job, $this->yangon(self::THURSDAY.' 10:00:00'), LspUser::factory()->forTeam($team)->create()),
            CapacityExceededException::class,
        );

        $this->assertNull($job->fresh()->appointment_date);
    }

    public function test_installation_and_on_call_jobs_share_one_counter(): void
    {
        $team = $this->teamWithThursdayCapacity(1);
        OnCallRequest::factory()->forTeam($team)->appointmentAt($this->yangon(self::THURSDAY.' 09:00:00'))->create();
        $job = InstallationRequest::factory()->forTeam($team)->create();

        $this->assertThrows(
            fn () => $this->service()->book($job, $this->yangon(self::THURSDAY.' 10:00:00'), LspUser::factory()->forTeam($team)->create()),
            CapacityExceededException::class,
        );
    }

    public function test_cancelled_and_soft_deleted_jobs_do_not_block_booking(): void
    {
        $team = $this->teamWithThursdayCapacity(1);
        InstallationRequest::factory()->forTeam($team)->withStatus(JobStatus::Cancelled)->appointmentAt($this->yangon(self::THURSDAY.' 09:00:00'))->create();
        OnCallRequest::factory()->forTeam($team)->appointmentAt($this->yangon(self::THURSDAY.' 09:00:00'))->create()->delete();
        $job = InstallationRequest::factory()->forTeam($team)->create();

        $result = $this->service()->book($job, $this->yangon(self::THURSDAY.' 10:00:00'), LspUser::factory()->forTeam($team)->create());

        $this->assertSame(BookingAction::Book, $result->action);
    }

    public function test_override_with_permission_exceeds_capacity_and_logs_override_with_reason(): void
    {
        $team = $this->teamWithThursdayCapacity(1);
        InstallationRequest::factory()->forTeam($team)->appointmentAt($this->yangon(self::THURSDAY.' 09:00:00'))->create();
        $job = OnCallRequest::factory()->forTeam($team)->create();
        $operator = $this->operatorWithOverride();

        $result = $this->service()->book($job, $this->yangon(self::THURSDAY.' 10:00:00'), $operator, 'Emergency customer outage');

        $this->assertSame(BookingAction::Override, $result->action);
        $this->assertSame(2, $this->usedSlots($team, self::THURSDAY));
        $this->assertSame(0, $result->capacity->remainingSlots());
        $this->assertDatabaseHas('appointment_booking_logs', [
            'request_type' => 'oncall',
            'request_id' => $job->id,
            'action' => 'override',
            'reason' => 'Emergency customer outage',
            'actor_user_id' => $operator->id,
        ]);
    }

    public function test_override_reason_with_free_capacity_is_a_normal_booking(): void
    {
        $team = LspTeam::factory()->create();
        $job = InstallationRequest::factory()->forTeam($team)->create();

        $result = $this->service()->book($job, $this->yangon(self::THURSDAY.' 10:00:00'), $this->operatorWithOverride(), 'Emergency customer outage');

        $this->assertSame(BookingAction::Book, $result->action);
    }

    public function test_override_without_permission_is_forbidden_and_leaves_job_unchanged(): void
    {
        $job = InstallationRequest::factory()->create();

        $this->assertThrows(
            fn () => $this->service()->book($job, $this->yangon(self::THURSDAY.' 10:00:00'), User::factory()->superAdmin()->create(), 'Emergency customer outage'),
            AuthorizationException::class,
        );

        $this->assertNull($job->fresh()->appointment_date);
    }

    public function test_override_reason_shorter_than_ten_characters_is_rejected(): void
    {
        $job = InstallationRequest::factory()->create();

        $this->assertThrows(
            fn () => $this->service()->book($job, $this->yangon(self::THURSDAY.' 10:00:00'), $this->operatorWithOverride(), ' too short '),
            ValidationException::class,
        );

        $this->assertNull($job->fresh()->appointment_date);
    }

    public function test_job_without_team_is_rejected_and_left_unchanged(): void
    {
        $job = InstallationRequest::factory()->withoutTeam()->create();

        $this->assertThrows(
            fn () => $this->service()->book($job, $this->yangon(self::THURSDAY.' 10:00:00'), User::factory()->superAdmin()->create()),
            MissingTeamAssignmentException::class,
        );

        $this->assertNull($job->fresh()->appointment_date);
    }

    public function test_reschedule_frees_old_day_uses_new_day_and_logs_reschedule(): void
    {
        $team = LspTeam::factory()->create();
        $job = InstallationRequest::factory()->forTeam($team)->appointmentAt($this->yangon(self::THURSDAY.' 10:00:00'))->create();

        $result = $this->service()->book($job, $this->yangon('2026-04-03 10:00:00'), LspUser::factory()->forTeam($team)->create());

        $this->assertSame(BookingAction::Reschedule, $result->action);
        $this->assertSame(0, $this->usedSlots($team, self::THURSDAY));
        $this->assertSame(1, $this->usedSlots($team, '2026-04-03'));
        $this->assertDatabaseHas('appointment_booking_logs', ['request_id' => $job->id, 'action' => 'reschedule', 'appointment_date' => '2026-04-03']);
    }

    public function test_reschedule_to_full_day_keeps_original_appointment(): void
    {
        $team = $this->teamWithThursdayCapacity(1);
        InstallationRequest::factory()->forTeam($team)->appointmentAt($this->yangon(self::THURSDAY.' 09:00:00'))->create();
        $job = InstallationRequest::factory()->forTeam($team)->appointmentAt($this->yangon('2026-04-01 10:00:00'))->create();

        $this->assertThrows(
            fn () => $this->service()->book($job, $this->yangon(self::THURSDAY.' 10:00:00'), LspUser::factory()->forTeam($team)->create()),
            CapacityExceededException::class,
        );

        $this->assertSame(1, $this->usedSlots($team, '2026-04-01'));
        $this->assertDatabaseHas('installation_requests', ['id' => $job->id, 'appointment_date' => '2026-04-01 03:30:00']);
    }

    public function test_yangon_just_after_midnight_counts_on_yangon_day_and_uses_yangon_weekday_capacity(): void
    {
        // Thursday 2026-04-02 00:30 in Yangon is still Wednesday 2026-04-01 18:00 in UTC.
        $team = $this->teamWithThursdayCapacity(1);
        $job = InstallationRequest::factory()->forTeam($team)->create();

        $result = $this->service()->book($job, $this->yangon(self::THURSDAY.' 00:30:00'), LspUser::factory()->forTeam($team)->create());

        $this->assertSame(self::THURSDAY, $result->capacity->date->toDateString());
        $this->assertSame(1, $result->capacity->maxSlots);
        $this->assertDatabaseHas('installation_requests', ['id' => $job->id, 'appointment_date' => '2026-04-01 18:00:00']);
        $this->assertSame(1, $this->usedSlots($team, self::THURSDAY));
        $this->assertSame(0, $this->usedSlots($team, '2026-04-01'));
    }

    public function test_utc_input_is_converted_to_the_yangon_day(): void
    {
        $team = LspTeam::factory()->create();
        $job = InstallationRequest::factory()->forTeam($team)->create();

        $this->service()->book($job, CarbonImmutable::parse('2026-04-01T18:00:00Z'), LspUser::factory()->forTeam($team)->create());

        $this->assertSame(1, $this->usedSlots($team, self::THURSDAY));
    }

    public function test_failed_audit_log_rolls_back_the_appointment_update(): void
    {
        $team = LspTeam::factory()->create();
        $job = InstallationRequest::factory()->forTeam($team)->create();
        $actor = LspUser::factory()->forTeam($team)->create();
        $this->app->bind(BookingLogRepositoryInterface::class, fn () => new class implements BookingLogRepositoryInterface
        {
            public function record($job, string $appointmentDate, BookingAction $action, $actor, ?string $reason = null): AppointmentBookingLog
            {
                throw new RuntimeException('Audit log unavailable.');
            }
        });

        $this->assertThrows(
            fn () => $this->service()->book($job, $this->yangon(self::THURSDAY.' 10:00:00'), $actor),
            RuntimeException::class,
            'Audit log unavailable.',
        );

        $this->assertNull($job->fresh()->appointment_date);
    }

    public function test_capacity_for_reports_spec_shape_without_creating_a_row(): void
    {
        $team = LspTeam::factory()->create();
        InstallationRequest::factory()->count(3)->forTeam($team)->appointmentAt($this->yangon(self::THURSDAY.' 09:00:00'))->create();

        $snapshot = $this->service()->capacityFor($team, self::THURSDAY);

        $this->assertSame(
            ['date' => self::THURSDAY, 'day_of_week' => 4, 'max_slots' => 8, 'used_slots' => 3, 'remaining_slots' => 5],
            $snapshot->toArray(),
        );
        $this->assertDatabaseCount('lsp_team_daily_capacities', 0);
    }

    public function test_capacity_for_never_reports_negative_remaining_after_override(): void
    {
        $team = $this->teamWithThursdayCapacity(1);
        InstallationRequest::factory()->count(2)->forTeam($team)->appointmentAt($this->yangon(self::THURSDAY.' 09:00:00'))->create();

        $this->assertSame(0, $this->service()->capacityFor($team, self::THURSDAY)->remainingSlots());
    }

    private function teamWithThursdayCapacity(int $maxSlots): LspTeam
    {
        $team = LspTeam::factory()->create();
        LspTeamDailyCapacity::factory()->for($team, 'team')->create(['day_of_week' => 4, 'max_slots' => $maxSlots]);

        return $team;
    }

    private function operatorWithOverride(): User
    {
        $operator = User::factory()->create();
        $operator->permissions()->attach(Permission::factory()->create(['name' => PermissionName::AppointmentOverride->value]));

        return $operator;
    }

    private function usedSlots(LspTeam $team, string $date): int
    {
        return $this->service()->capacityFor($team, $date)->usedSlots;
    }

    private function yangon(string $dateTime): CarbonImmutable
    {
        return CarbonImmutable::parse($dateTime, 'Asia/Yangon');
    }

    private function service(): AppointmentCapacityService
    {
        return $this->app->make(AppointmentCapacityService::class);
    }
}
