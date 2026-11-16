<?php

namespace App\Http\Requests;

use App\Models\InstallationRequest;
use App\Models\OnCallRequest;
use App\Services\AppointmentCapacityService;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use LogicException;
use Throwable;

/**
 * Shared by the installation and on-call booking endpoints.
 */
class BookAppointmentRequest extends FormRequest
{
    public const int MAX_DAYS_AHEAD = 60;

    /**
     * Runs before validation: a forbidden caller gets 403 without learning anything from 422s.
     * Sending override_reason at all is an override attempt and needs the override permission.
     */
    public function authorize(): Response
    {
        $response = Gate::inspect('bookAppointment', $this->job());

        if ($response->allowed() && $this->isOverrideAttempt()) {
            return Gate::inspect('overrideCapacity', $this->job());
        }

        return $response;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'appointment_date' => ['required', 'string', 'date', $this->withinBookingWindow(...)],
            'override_reason' => ['sometimes', 'required', 'string', 'min:'.AppointmentCapacityService::MIN_OVERRIDE_REASON_LENGTH, 'max:1000'],
        ];
    }

    /**
     * The job bound to this route ({installationRequest} or {onCallRequest}).
     */
    public function job(): InstallationRequest|OnCallRequest
    {
        foreach ($this->route()->parameters() as $parameter) {
            if ($parameter instanceof InstallationRequest || $parameter instanceof OnCallRequest) {
                return $parameter;
            }
        }

        throw new LogicException('BookAppointmentRequest requires a bound installation or on-call request.');
    }

    /**
     * A datetime without an offset is business (Asia/Yangon) local time; an explicit offset wins.
     */
    public function appointmentDate(): CarbonImmutable
    {
        return CarbonImmutable::parse($this->validated('appointment_date'), config('app.business_timezone'));
    }

    public function overrideReason(): ?string
    {
        return $this->validated('override_reason');
    }

    private function isOverrideAttempt(): bool
    {
        return $this->exists('override_reason');
    }

    /**
     * The appointment's business calendar day may be at most MAX_DAYS_AHEAD days after today's.
     * Past dates are not rejected; the assignment only limits how far ahead a booking may be.
     */
    private function withinBookingWindow(string $attribute, mixed $value, Closure $fail): void
    {
        $timezone = config('app.business_timezone');

        try {
            $day = CarbonImmutable::parse($value, $timezone)->setTimezone($timezone)->startOfDay();
        } catch (Throwable) {
            return; // The "date" rule reports unparseable values.
        }

        $lastBookableDay = CarbonImmutable::now($timezone)->startOfDay()->addDays(self::MAX_DAYS_AHEAD);

        if ($day->greaterThan($lastBookableDay)) {
            $fail("The {$attribute} must not be more than ".self::MAX_DAYS_AHEAD." days after today ({$lastBookableDay->toDateString()} is the last bookable day).");
        }
    }
}
