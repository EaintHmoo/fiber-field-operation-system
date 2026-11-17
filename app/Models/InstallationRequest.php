<?php

namespace App\Models;

use App\Models\Concerns\HasAppointmentBooking;
use Database\Factories\InstallationRequestFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A customer connection job (new install or relocation).
 */
class InstallationRequest extends Model
{
    /** @use HasFactory<InstallationRequestFactory> */
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
