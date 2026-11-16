<?php

namespace App\Repositories\Eloquent;

use App\Enums\BookingAction;
use App\Models\AppointmentBookingLog;
use App\Models\InstallationRequest;
use App\Models\LspUser;
use App\Models\OnCallRequest;
use App\Models\User;
use App\Repositories\Contracts\BookingLogRepositoryInterface;

class EloquentBookingLogRepository implements BookingLogRepositoryInterface
{
    public function record(
        InstallationRequest|OnCallRequest $job,
        string $appointmentDate,
        BookingAction $action,
        User|LspUser $actor,
        ?string $reason = null,
    ): AppointmentBookingLog {
        // request_type / request_id are filled from the morph map alias of $job.
        return $job->bookingLogs()->create([
            'lsp_team_id' => $job->current_lsp_team_id,
            'appointment_date' => $appointmentDate,
            'action' => $action,
            'reason' => $reason,
            'actor_user_id' => $actor instanceof User ? $actor->id : null,
            'actor_lsp_user_id' => $actor instanceof LspUser ? $actor->id : null,
        ]);
    }
}
