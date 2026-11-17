<?php

namespace App\Models;

use Database\Factories\LspUserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;

/**
 * Contractor (LSP) field-team member. Authenticates with Sanctum personal access tokens.
 */
class LspUser extends Authenticatable
{
    /** @use HasFactory<LspUserFactory> */
    use HasApiTokens, HasFactory;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'lsp_users';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'lsp_id',
        'lsp_team_id',
        'name',
        'email',
        'password',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'password' => 'hashed',
        ];
    }

    /**
     * @return BelongsTo<Lsp, $this>
     */
    public function lsp(): BelongsTo
    {
        return $this->belongsTo(Lsp::class);
    }

    /**
     * @return BelongsTo<LspTeam, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(LspTeam::class, 'lsp_team_id');
    }
}
