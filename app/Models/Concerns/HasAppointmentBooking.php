<?php

namespace App\Models\Concerns;

use App\Models\AppointmentBookingLog;
use App\Models\Lsp;
use App\Models\LspTeam;
use App\Models\Status;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Carbon;

/**
 * Booking-related columns and relationships shared by installation and on-call requests.
 *
 * @property int $status_id
 * @property int|null $current_lsp_id
 * @property int|null $current_lsp_team_id
 * @property Carbon|null $appointment_date
 */
trait HasAppointmentBooking
{
    /**
     * Merge the booking casts into the using model.
     */
    public function initializeHasAppointmentBooking(): void
    {
        $this->mergeCasts([
            'appointment_date' => 'datetime',
        ]);
    }

    /**
     * Eloquent formats a Carbon value without converting its timezone, so a Yangon-time value
     * would be stored as Yangon wall-clock time. Normalise every write to the storage timezone.
     *
     * @return Attribute<Carbon|null, DateTimeInterface|string|null>
     */
    protected function appointmentDate(): Attribute
    {
        return Attribute::make(
            set: fn (DateTimeInterface|string|null $value): ?string => match (true) {
                $value === null => null,
                $value instanceof DateTimeInterface => $this->fromDateTime(
                    CarbonImmutable::instance($value)->setTimezone(config('app.timezone')),
                ),
                default => $this->fromDateTime($value),
            },
        );
    }

    /**
     * @return BelongsTo<Status, $this>
     */
    public function status(): BelongsTo
    {
        return $this->belongsTo(Status::class);
    }

    /**
     * @return BelongsTo<Lsp, $this>
     */
    public function currentLsp(): BelongsTo
    {
        return $this->belongsTo(Lsp::class, 'current_lsp_id');
    }

    /**
     * @return BelongsTo<LspTeam, $this>
     */
    public function currentLspTeam(): BelongsTo
    {
        return $this->belongsTo(LspTeam::class, 'current_lsp_team_id');
    }

    /**
     * @return MorphMany<AppointmentBookingLog, $this>
     */
    public function bookingLogs(): MorphMany
    {
        return $this->morphMany(AppointmentBookingLog::class, 'request');
    }
}
