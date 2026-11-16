<?php

namespace Tests\Feature\Policies;

use App\Models\LspTeam;
use App\Models\LspUser;
use App\Models\OnCallRequest;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

class OnCallRequestPolicyTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_allows_booking_by_member_of_assigned_team(): void
    {
        $job = OnCallRequest::factory()->create();
        $lspUser = LspUser::factory()->forTeam($job->currentLspTeam)->create();

        $this->assertTrue(Gate::forUser($lspUser)->allows('bookAppointment', $job));
    }

    public function test_denies_booking_by_other_team_with_message(): void
    {
        $job = OnCallRequest::factory()->create();
        $lspUser = LspUser::factory()->forTeam(LspTeam::factory()->create())->create();

        $response = Gate::forUser($lspUser)->inspect('bookAppointment', $job);

        $this->assertTrue($response->denied());
        $this->assertSame('You are not allowed to book appointments for this job.', $response->message());
    }

    public function test_denies_guest(): void
    {
        $job = OnCallRequest::factory()->create();

        $this->assertTrue(Gate::forUser(null)->denies('bookAppointment', $job));
    }
}
