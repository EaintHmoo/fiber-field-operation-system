<?php

namespace App\Repositories\Contracts;

use App\Enums\BookingAction;
use App\Models\AppointmentBookingLog;
use App\Models\InstallationRequest;
use App\Models\LspUser;
use App\Models\OnCallRequest;
use App\Models\User;

interface BookingLogRepositoryInterface
{
    /**
     * Write an audit row for a booking change on the job's current team.
     *
     * @param  string  $appointmentDate  Business (Asia/Yangon) calendar date, Y-m-d.
     */
    public function record(
        InstallationRequest|OnCallRequest $job,
        string $appointmentDate,
        BookingAction $action,
        User|LspUser $actor,
        ?string $reason = null,
    ): AppointmentBookingLog;
}
