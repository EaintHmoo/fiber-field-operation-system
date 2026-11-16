<?php

namespace App\Models;

use Database\Factories\LspFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name'])]
class Lsp extends Model
{
    /** @use HasFactory<LspFactory> */
    use HasFactory;

    /**
     * @return HasMany<LspTeam, $this>
     */
    public function teams(): HasMany
    {
        return $this->hasMany(LspTeam::class);
    }

    /**
     * @return HasMany<LspUser, $this>
     */
    public function users(): HasMany
    {
        return $this->hasMany(LspUser::class);
    }

    /**
     * @return BelongsToMany<AreaCode, $this>
     */
    public function areaCodes(): BelongsToMany
    {
        return $this->belongsToMany(AreaCode::class, 'lsp_area_code');
    }
}
