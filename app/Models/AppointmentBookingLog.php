<?php

namespace App\Models;

use App\Enums\BookingAction;
use App\Enums\RequestType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Audit row for a booking change. request_type + request_id reference either
 * installation_requests or on_call_requests through the morph map.
 */
#[Fillable([
    'request_type',
    'request_id',
    'lsp_team_id',
    'appointment_date',
    'action',
    'reason',
    'actor_user_id',
    'actor_lsp_user_id',
])]
class AppointmentBookingLog extends Model
{
    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'appointment_date' => 'date:Y-m-d',
            'action' => BookingAction::class,
        ];
    }

    /**
     * The job type as an enum. request_type itself is not cast because MorphTo resolves the raw alias.
     */
    public function requestType(): RequestType
    {
        return RequestType::from($this->request_type);
    }

    /**
     * The booked job; soft-deleted jobs are still resolved so history stays readable.
     *
     * @return MorphTo<Model, $this>
     */
    public function request(): MorphTo
    {
        return $this->morphTo('request', 'request_type', 'request_id')->withTrashed();
    }

    /**
     * @return BelongsTo<LspTeam, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(LspTeam::class, 'lsp_team_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function actorUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    /**
     * @return BelongsTo<LspUser, $this>
     */
    public function actorLspUser(): BelongsTo
    {
        return $this->belongsTo(LspUser::class, 'actor_lsp_user_id');
    }
}
