<?php

namespace App\Services;

use App\Enums\PermissionName;
use App\Models\AreaCode;
use App\Models\InstallationRequest;
use App\Models\LspTeam;
use App\Models\LspUser;
use App\Models\OnCallRequest;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Single source of truth for who may act on which LSP team. Policies delegate here.
 *
 * - LSP user: only their own lsp_team_id.
 * - Operator: super admin, or an area code shared between the operator's teams and the target LSP.
 */
class AuthorizationService
{
    /**
     * Whether the actor may book or reschedule this job.
     */
    public function canBookJob(User|LspUser $actor, InstallationRequest|OnCallRequest $job): bool
    {
        if ($actor instanceof LspUser) {
            return $job->current_lsp_team_id !== null
                && (int) $job->current_lsp_team_id === (int) $actor->lsp_team_id;
        }

        // Prefer the team's LSP: MySQL skips the composite (team, lsp) foreign key when
        // current_lsp_id is NULL, so the team is the trustworthy source when one is assigned.
        $lspId = $job->currentLspTeam?->lsp_id ?? $job->current_lsp_id;

        return $this->operatorCanAccessLsp($actor, $lspId);
    }

    /**
     * Whether the actor may read or book against this team's capacity.
     */
    public function canAccessTeam(User|LspUser $actor, LspTeam $team): bool
    {
        if ($actor instanceof LspUser) {
            return (int) $actor->lsp_team_id === (int) $team->id;
        }

        return $this->operatorCanAccessLsp($actor, $team->lsp_id);
    }

    /**
     * Whether the actor may book beyond a team's daily capacity.
     */
    public function canOverrideCapacity(User|LspUser $actor): bool
    {
        return $actor instanceof User
            && $actor->hasPermission(PermissionName::AppointmentOverride);
    }

    /**
     * Super admins reach every LSP; other operators need at least one area code that is linked
     * both to one of their operator teams and to the LSP.
     */
    public function operatorCanAccessLsp(User $operator, ?int $lspId): bool
    {
        if ($operator->is_super_admin) {
            return true;
        }

        if ($lspId === null) {
            return false;
        }

        return AreaCode::query()
            ->whereHas('lsps', fn (Builder $query) => $query->whereKey($lspId))
            ->whereHas('operatorTeams.users', fn (Builder $query) => $query->whereKey($operator->id))
            ->exists();
    }
}
