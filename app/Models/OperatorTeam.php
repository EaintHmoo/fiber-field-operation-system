<?php

namespace App\Models;

use Database\Factories\OperatorTeamFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Internal 5BB team; its area codes define which LSPs its operators may act on.
 */
#[Fillable(['name'])]
class OperatorTeam extends Model
{
    /** @use HasFactory<OperatorTeamFactory> */
    use HasFactory;

    /**
     * @return BelongsToMany<User, $this>
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class);
    }

    /**
     * @return BelongsToMany<AreaCode, $this>
     */
    public function areaCodes(): BelongsToMany
    {
        return $this->belongsToMany(AreaCode::class, 'operator_team_area_code');
    }
}
