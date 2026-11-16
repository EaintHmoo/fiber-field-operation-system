<?php

namespace App\Policies;

use App\Models\LspTeam;
use App\Models\LspUser;
use App\Models\User;
use App\Services\AuthorizationService;
use Illuminate\Auth\Access\Response;

class LspTeamPolicy
{
    public function __construct(private AuthorizationService $authorization) {}

    /**
     * View the team's daily capacity.
     */
    public function viewCapacity(User|LspUser $actor, LspTeam $team): Response
    {
        return $this->authorization->canAccessTeam($actor, $team)
            ? Response::allow()
            : Response::deny('You are not allowed to view this team\'s capacity.');
    }
}
