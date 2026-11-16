<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\BookAppointmentRequest;
use App\Http\Resources\AppointmentBookingResource;
use App\Models\OnCallRequest;
use App\Services\AppointmentCapacityService;

class OnCallAppointmentController extends Controller
{
    public function __construct(private AppointmentCapacityService $capacity) {}

    /**
     * Book or reschedule an on-call request's appointment.
     *
     * Authorization (team / area code / override permission) and validation run in the form request.
     */
    public function store(BookAppointmentRequest $request, OnCallRequest $onCallRequest): AppointmentBookingResource
    {
        return new AppointmentBookingResource($this->capacity->book(
            $onCallRequest,
            $request->appointmentDate(),
            $request->user(),
            $request->overrideReason(),
        ));
    }
}
