<?php

namespace Tests\Feature\Policies;

use App\Enums\PermissionName;
use App\Models\InstallationRequest;
use App\Models\LspTeam;
use App\Models\LspUser;
use App\Models\Permission;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

class InstallationRequestPolicyTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_allows_booking_by_member_of_assigned_team(): void
    {
        $job = InstallationRequest::factory()->create();
        $lspUser = LspUser::factory()->forTeam($job->currentLspTeam)->create();

        $this->assertTrue(Gate::forUser($lspUser)->allows('bookAppointment', $job));
    }

    public function test_denies_booking_by_other_team_with_message(): void
    {
        $job = InstallationRequest::factory()->create();
        $lspUser = LspUser::factory()->forTeam(LspTeam::factory()->create())->create();

        $response = Gate::forUser($lspUser)->inspect('bookAppointment', $job);

        $this->assertTrue($response->denied());
        $this->assertSame('You are not allowed to book appointments for this job.', $response->message());
    }

    public function test_allows_override_for_operator_with_permission(): void
    {
        $job = InstallationRequest::factory()->create();
        $operator = User::factory()->create();
        $operator->permissions()->attach(Permission::factory()->create(['name' => PermissionName::AppointmentOverride->value]));

        $this->assertTrue(Gate::forUser($operator)->allows('overrideCapacity', $job));
    }

    public function test_denies_override_without_permission_with_message(): void
    {
        $job = InstallationRequest::factory()->create();

        $response = Gate::forUser(User::factory()->create())->inspect('overrideCapacity', $job);

        $this->assertTrue($response->denied());
        $this->assertSame('You do not have permission to override appointment capacity.', $response->message());
    }

    public function test_denies_guest(): void
    {
        $job = InstallationRequest::factory()->create();

        $this->assertTrue(Gate::forUser(null)->denies('bookAppointment', $job));
    }
}
