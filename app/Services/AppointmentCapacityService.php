<?php

namespace App\Services;

use App\Enums\BookingAction;
use App\Enums\RequestType;
use App\Exceptions\CapacityExceededException;
use App\Exceptions\MissingTeamAssignmentException;
use App\Models\InstallationRequest;
use App\Models\LspTeam;
use App\Models\LspTeamDailyCapacity;
use App\Models\LspUser;
use App\Models\OnCallRequest;
use App\Models\User;
use App\Repositories\Contracts\AppointmentRepositoryInterface;
use App\Repositories\Contracts\BookingLogRepositoryInterface;
use App\Repositories\Contracts\CapacityRepositoryInterface;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Daily slot accounting and booking for LSP teams. Installation and on-call jobs share one
 * counter per team per business (Asia/Yangon) calendar day.
 *
 * Used slots are derived from appointment_date, never stored, so moving a job to another day
 * frees the old day and consumes the new one in the same UPDATE, inside one transaction.
 */
class AppointmentCapacityService
{
    public const int MIN_OVERRIDE_REASON_LENGTH = 10;

    /**
     * Retries absorb InnoDB deadlocks from racing inserts of a missing capacity row.
     */
    private const int TRANSACTION_ATTEMPTS = 3;

    public function __construct(
        private AppointmentRepositoryInterface $appointments,
        private CapacityRepositoryInterface $capacities,
        private BookingLogRepositoryInterface $bookingLogs,
        private AuthorizationService $authorization,
    ) {}

    /**
     * Read-only capacity for a team on a business date (Y-m-d). Does not create capacity rows.
     */
    public function capacityFor(LspTeam $team, string $date): CapacitySnapshot
    {
        $day = CarbonImmutable::createFromFormat('!Y-m-d', $date, $this->timezone());

        $maxSlots = $this->capacities->find($team->id, $day->dayOfWeek)?->max_slots
            ?? LspTeamDailyCapacity::DEFAULT_MAX_SLOTS;

        return new CapacitySnapshot($day, $maxSlots, $this->countUsedSlots($team->id, $day));
    }

    /**
     * Book or reschedule a job's appointment on its current team.
     *
     * Passing an override reason requests a capacity override: it requires the override
     * permission even when slots remain, and is only used when the day is full.
     *
     * @throws AuthorizationException when an override is requested without permission
     * @throws ValidationException when the override reason is too short
     * @throws MissingTeamAssignmentException when the job has no current team
     * @throws CapacityExceededException when the day is full and no override was requested
     */
    public function book(
        InstallationRequest|OnCallRequest $job,
        DateTimeInterface $appointmentDate,
        User|LspUser $actor,
        ?string $overrideReason = null,
    ): BookingResult {
        $overrideRequested = $overrideReason !== null;

        if ($overrideRequested && ! $this->authorization->canOverrideCapacity($actor)) {
            throw new AuthorizationException('You do not have permission to override appointment capacity.');
        }

        if ($overrideRequested && mb_strlen(trim($overrideReason)) < self::MIN_OVERRIDE_REASON_LENGTH) {
            throw ValidationException::withMessages([
                'override_reason' => 'The override reason must be at least '.self::MIN_OVERRIDE_REASON_LENGTH.' characters.',
            ]);
        }

        $appointmentDate = CarbonImmutable::instance($appointmentDate);
        $day = $this->businessDay($appointmentDate);
        $type = RequestType::forModel($job);
        $jobId = $job->getKey();

        return DB::transaction(function () use ($type, $jobId, $appointmentDate, $day, $actor, $overrideRequested, $overrideReason): BookingResult {
            // Re-read under a row lock so concurrent requests for the same job see its latest date.
            $job = $this->appointments->findForUpdate($type, $jobId);

            if ($job->current_lsp_team_id === null) {
                throw new MissingTeamAssignmentException;
            }

            $teamId = (int) $job->current_lsp_team_id;
            $previousDate = $job->appointment_date;

            if ($previousDate !== null && $this->businessDay($previousDate)->equalTo($day)) {
                return $this->rebookSameDay($job, $appointmentDate, $day, $teamId);
            }

            // Serializes every booking for this team and weekday until commit.
            $capacity = $this->capacities->lockOrCreate($teamId, $day->dayOfWeek, LspTeamDailyCapacity::DEFAULT_MAX_SLOTS);
            $usedSlots = $this->countUsedSlots($teamId, $day);
            $isFull = $usedSlots >= $capacity->max_slots;

            if ($isFull && ! $overrideRequested) {
                throw new CapacityExceededException(
                    new CapacitySnapshot($day, $capacity->max_slots, $usedSlots),
                    $this->authorization->canOverrideCapacity($actor),
                );
            }

            $action = match (true) {
                $isFull => BookingAction::Override,
                $previousDate !== null => BookingAction::Reschedule,
                default => BookingAction::Book,
            };

            $this->appointments->updateAppointmentDate($job, $appointmentDate);
            $this->bookingLogs->record($job, $day->toDateString(), $action, $actor, $overrideReason);

            return new BookingResult($job, $action, new CapacitySnapshot($day, $capacity->max_slots, $usedSlots + 1));
        }, self::TRANSACTION_ATTEMPTS);
    }

    /**
     * Same job, same team, same business day: succeed without using another slot or logging.
     * A different time on that day is still saved.
     */
    private function rebookSameDay(
        InstallationRequest|OnCallRequest $job,
        CarbonImmutable $appointmentDate,
        CarbonImmutable $day,
        int $teamId,
    ): BookingResult {
        if (! $job->appointment_date->equalTo($appointmentDate)) {
            $this->appointments->updateAppointmentDate($job, $appointmentDate);
        }

        $maxSlots = $this->capacities->find($teamId, $day->dayOfWeek)?->max_slots
            ?? LspTeamDailyCapacity::DEFAULT_MAX_SLOTS;

        return new BookingResult($job, null, new CapacitySnapshot($day, $maxSlots, $this->countUsedSlots($teamId, $day)));
    }

    /**
     * Count slots used on a business day: [00:00, next 00:00) in the business timezone.
     */
    private function countUsedSlots(int $teamId, CarbonImmutable $day): int
    {
        return $this->appointments->countUsedSlots($teamId, $day, $day->addDay());
    }

    /**
     * Start of the business-timezone calendar day containing the given moment.
     */
    private function businessDay(DateTimeInterface $moment): CarbonImmutable
    {
        return CarbonImmutable::instance($moment)->setTimezone($this->timezone())->startOfDay();
    }

    private function timezone(): string
    {
        return config('app.business_timezone');
    }
}
