<?php

namespace App\Models\Concerns;

use App\Models\AppointmentBookingLog;
use App\Models\Lsp;
use App\Models\LspTeam;
use App\Models\Status;
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
