<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\BookAppointmentRequest;
use App\Http\Resources\AppointmentBookingResource;
use App\Models\InstallationRequest;
use App\Services\AppointmentCapacityService;

class InstallationAppointmentController extends Controller
{
    public function __construct(private AppointmentCapacityService $capacity) {}

    /**
     * Book or reschedule an installation request's appointment.
     *
     * Authorization (team / area code / override permission) and validation run in the form request.
     */
    public function store(BookAppointmentRequest $request, InstallationRequest $installationRequest): AppointmentBookingResource
    {
        return new AppointmentBookingResource($this->capacity->book(
            $installationRequest,
            $request->appointmentDate(),
            $request->user(),
            $request->overrideReason(),
        ));
    }
}
