<?php

namespace App\Http\Resources;

use App\Enums\BookingAction;
use App\Enums\RequestType;
use App\Services\BookingResult;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read BookingResult $resource
 */
class AppointmentBookingResource extends JsonResource
{
    public function __construct(BookingResult $result)
    {
        parent::__construct($result);

        $this->additional(['message' => $this->message()]);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $job = $this->resource->job;

        return [
            'request_type' => RequestType::forModel($job)->value,
            'request_id' => $job->getKey(),
            'lsp_team_id' => $job->current_lsp_team_id,
            'appointment_date' => $job->appointment_date->setTimezone(config('app.business_timezone'))->toIso8601String(),
            'action' => $this->resource->action?->value,
            'idempotent' => $this->resource->wasIdempotent(),
            'capacity' => $this->resource->capacity->toArray(),
        ];
    }

    private function message(): string
    {
        return match ($this->resource->action) {
            BookingAction::Book => 'Appointment booked.',
            BookingAction::Reschedule => 'Appointment rescheduled.',
            BookingAction::Override => 'Appointment booked over capacity (override recorded).',
            null => 'Appointment already booked on this date; no additional slot used.',
        };
    }
}
