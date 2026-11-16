<?php

namespace Tests\Feature\Policies;

use App\Models\LspTeam;
use App\Models\LspUser;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

class LspTeamPolicyTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_allows_team_member_to_view_own_capacity(): void
    {
        $lspUser = LspUser::factory()->create();

        $this->assertTrue(Gate::forUser($lspUser)->allows('viewCapacity', $lspUser->team));
    }

    public function test_denies_other_team_member_with_message(): void
    {
        $lspUser = LspUser::factory()->create();

        $response = Gate::forUser($lspUser)->inspect('viewCapacity', LspTeam::factory()->create());

        $this->assertTrue($response->denied());
        $this->assertSame('You are not allowed to view this team\'s capacity.', $response->message());
    }

    public function test_denies_operator_outside_team_area(): void
    {
        $this->assertTrue(Gate::forUser(User::factory()->create())->denies('viewCapacity', LspTeam::factory()->create()));
    }

    public function test_denies_guest(): void
    {
        $this->assertTrue(Gate::forUser(null)->denies('viewCapacity', LspTeam::factory()->create()));
    }
}
