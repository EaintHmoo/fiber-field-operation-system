<?php

namespace App\Policies;

use App\Models\LspUser;
use App\Models\OnCallRequest;
use App\Models\User;
use App\Services\AuthorizationService;
use Illuminate\Auth\Access\Response;

class OnCallRequestPolicy
{
    public function __construct(private AuthorizationService $authorization) {}

    /**
     * Book or reschedule the job's appointment.
     */
    public function bookAppointment(User|LspUser $actor, OnCallRequest $job): Response
    {
        return $this->authorization->canBookJob($actor, $job)
            ? Response::allow()
            : Response::deny('You are not allowed to book appointments for this job.');
    }
}
