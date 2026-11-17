<?php

namespace App\Models;

use Database\Factories\LspTeamDailyCapacityFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Per-weekday slot limit for a team. Weekday is Carbon dayOfWeek in Asia/Yangon (0 = Sunday).
 */
class LspTeamDailyCapacity extends Model
{
    /** @use HasFactory<LspTeamDailyCapacityFactory> */
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'lsp_team_id',
        'day_of_week',
        'max_slots',
    ];

    public const int DEFAULT_MAX_SLOTS = 8;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'day_of_week' => 'integer',
            'max_slots' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<LspTeam, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(LspTeam::class, 'lsp_team_id');
    }
}
