<?php

namespace App\Models;

use Database\Factories\AreaCodeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class AreaCode extends Model
{
    /** @use HasFactory<AreaCodeFactory> */
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'label',
    ];

    /**
     * @return BelongsToMany<Lsp, $this>
     */
    public function lsps(): BelongsToMany
    {
        return $this->belongsToMany(Lsp::class, 'lsp_area_code');
    }

    /**
     * @return BelongsToMany<OperatorTeam, $this>
     */
    public function operatorTeams(): BelongsToMany
    {
        return $this->belongsToMany(OperatorTeam::class, 'operator_team_area_code');
    }
}
