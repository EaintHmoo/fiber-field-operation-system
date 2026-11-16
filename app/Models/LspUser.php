<?php

namespace App\Models;

use Database\Factories\LspUserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;

/**
 * Contractor (LSP) field-team member. Authenticates with Sanctum personal access tokens.
 */
#[Table('lsp_users')]
#[Fillable(['lsp_id', 'lsp_team_id', 'name', 'email', 'password'])]
#[Hidden(['password'])]
class LspUser extends Authenticatable
{
    /** @use HasFactory<LspUserFactory> */
    use HasApiTokens, HasFactory;

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
