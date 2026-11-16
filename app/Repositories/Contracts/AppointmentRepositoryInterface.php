<?php

namespace App\Repositories\Contracts;

use App\Enums\RequestType;
use App\Models\InstallationRequest;
use App\Models\OnCallRequest;
use DateTimeInterface;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * Persistence for bookable jobs. Installation and on-call requests live in separate tables
 * but share one capacity counter, so every method works across both.
 */
interface AppointmentRepositoryInterface
{
    /**
     * Find a job by type and id, excluding soft-deleted jobs.
     *
     * @throws ModelNotFoundException
     */
    public function find(RequestType $type, int $id): InstallationRequest|OnCallRequest;

    /**
     * Re-read a job with an exclusive row lock. Must be called inside a transaction.
     *
     * @throws ModelNotFoundException
     */
    public function findForUpdate(RequestType $type, int $id): InstallationRequest|OnCallRequest;

    /**
     * Count slot-consuming jobs (both tables) for a team with an appointment in [from, until).
     *
     * Soft-deleted jobs and jobs in a non-slot-consuming status are excluded.
     */
    public function countUsedSlots(int $lspTeamId, DateTimeInterface $from, DateTimeInterface $until): int;

    /**
     * Persist a new appointment date for the job.
     */
    public function updateAppointmentDate(InstallationRequest|OnCallRequest $job, DateTimeInterface $appointmentDate): void;
}
