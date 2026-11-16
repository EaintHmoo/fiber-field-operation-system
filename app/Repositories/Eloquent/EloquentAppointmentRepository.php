<?php

namespace App\Repositories\Eloquent;

use App\Enums\JobStatus;
use App\Enums\RequestType;
use App\Models\InstallationRequest;
use App\Models\OnCallRequest;
use App\Models\Status;
use App\Repositories\Contracts\AppointmentRepositoryInterface;
use Carbon\CarbonImmutable;
use DateTimeInterface;

class EloquentAppointmentRepository implements AppointmentRepositoryInterface
{
    public function find(RequestType $type, int $id): InstallationRequest|OnCallRequest
    {
        return $type->modelClass()::query()->findOrFail($id);
    }

    public function findForUpdate(RequestType $type, int $id): InstallationRequest|OnCallRequest
    {
        return $type->modelClass()::query()->lockForUpdate()->findOrFail($id);
    }

    public function countUsedSlots(int $lspTeamId, DateTimeInterface $from, DateTimeInterface $until): int
    {
        $from = $this->toStorageTimezone($from);
        $until = $this->toStorageTimezone($until);

        $nonSlotConsumingStatusIds = Status::query()
            ->whereIn('name', array_map(fn (JobStatus $status) => $status->value, JobStatus::nonSlotConsuming()))
            ->select('id');

        $total = 0;

        foreach (RequestType::cases() as $type) {
            // SoftDeletes' global scope excludes deleted_at IS NOT NULL.
            $total += $type->modelClass()::query()
                ->where('current_lsp_team_id', $lspTeamId)
                ->where('appointment_date', '>=', $from)
                ->where('appointment_date', '<', $until)
                ->whereNotIn('status_id', $nonSlotConsumingStatusIds)
                ->count();
        }

        return $total;
    }

    public function updateAppointmentDate(InstallationRequest|OnCallRequest $job, DateTimeInterface $appointmentDate): void
    {
        // The model's appointment_date mutator converts to the storage timezone.
        $job->appointment_date = $appointmentDate;
        $job->save();
    }

    /**
     * The query builder formats date bindings without converting timezones, so normalise
     * range bounds to the storage timezone (config app.timezone, UTC).
     */
    private function toStorageTimezone(DateTimeInterface $date): CarbonImmutable
    {
        return CarbonImmutable::instance($date)->setTimezone(config('app.timezone'));
    }
}
