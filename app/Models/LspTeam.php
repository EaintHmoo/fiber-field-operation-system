<?php

namespace App\Models;

use Database\Factories\LspTeamFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['lsp_id', 'name'])]
class LspTeam extends Model
{
    /** @use HasFactory<LspTeamFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<Lsp, $this>
     */
    public function lsp(): BelongsTo
    {
        return $this->belongsTo(Lsp::class);
    }

    /**
     * @return HasMany<LspUser, $this>
     */
    public function users(): HasMany
    {
        return $this->hasMany(LspUser::class);
    }

    /**
     * @return HasMany<InstallationRequest, $this>
     */
    public function installationRequests(): HasMany
    {
        return $this->hasMany(InstallationRequest::class, 'current_lsp_team_id');
    }

    /**
     * @return HasMany<OnCallRequest, $this>
     */
    public function onCallRequests(): HasMany
    {
        return $this->hasMany(OnCallRequest::class, 'current_lsp_team_id');
    }

    /**
     * @return HasMany<LspTeamDailyCapacity, $this>
     */
    public function dailyCapacities(): HasMany
    {
        return $this->hasMany(LspTeamDailyCapacity::class);
    }
}
