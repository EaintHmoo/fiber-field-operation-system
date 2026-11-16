<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\BookAppointmentRequest;
use App\Http\Resources\AppointmentBookingResource;
use App\Models\InstallationRequest;
use App\Services\AppointmentCapacityService;

class InstallationAppointmentController extends Controller
{
    /**
     * Book or reschedule an installation request's appointment.
     *
     * Authorization (team / area code / override permission) and validation run in the form request.
     */
    public function store(BookAppointmentRequest $request, InstallationRequest $installationRequest, AppointmentCapacityService $capacity): AppointmentBookingResource
    {
        return new AppointmentBookingResource($capacity->book(
            $installationRequest,
            $request->appointmentDate(),
            $request->user(),
            $request->overrideReason(),
        ));
    }
}
