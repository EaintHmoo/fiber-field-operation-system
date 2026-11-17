<?php

namespace App\Models;

use App\Models\Concerns\HasAppointmentBooking;
use Database\Factories\OnCallRequestFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A repair / maintenance visit.
 */
class OnCallRequest extends Model
{
    /** @use HasFactory<OnCallRequestFactory> */
    use HasAppointmentBooking, HasFactory, SoftDeletes;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'status_id',
        'current_lsp_id',
        'current_lsp_team_id',
        'appointment_date',
    ];
}
