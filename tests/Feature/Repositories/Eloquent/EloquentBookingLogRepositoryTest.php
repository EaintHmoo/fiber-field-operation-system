<?php

namespace Tests\Feature\Repositories\Eloquent;

use App\Enums\BookingAction;
use App\Models\InstallationRequest;
use App\Models\LspUser;
use App\Models\OnCallRequest;
use App\Models\User;
use App\Repositories\Contracts\BookingLogRepositoryInterface;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class EloquentBookingLogRepositoryTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_records_installation_booking_by_lsp_user(): void
    {
        $job = InstallationRequest::factory()->create();
        $lspUser = LspUser::factory()->forTeam($job->currentLspTeam)->create();

        $this->repository()->record($job, '2026-04-02', BookingAction::Book, $lspUser);

        $this->assertDatabaseHas('appointment_booking_logs', [
            'request_type' => 'installation',
            'request_id' => $job->id,
            'lsp_team_id' => $job->current_lsp_team_id,
            'appointment_date' => '2026-04-02',
            'action' => 'book',
            'reason' => null,
            'actor_user_id' => null,
            'actor_lsp_user_id' => $lspUser->id,
        ]);
    }

    public function test_records_on_call_override_by_operator_with_reason(): void
    {
        $job = OnCallRequest::factory()->create();
        $operator = User::factory()->create();

        $this->repository()->record($job, '2026-04-02', BookingAction::Override, $operator, 'Emergency customer outage');

        $this->assertDatabaseHas('appointment_booking_logs', [
            'request_type' => 'oncall',
            'request_id' => $job->id,
            'action' => 'override',
            'reason' => 'Emergency customer outage',
            'actor_user_id' => $operator->id,
            'actor_lsp_user_id' => null,
        ]);
    }

    private function repository(): BookingLogRepositoryInterface
    {
        return $this->app->make(BookingLogRepositoryInterface::class);
    }
}
