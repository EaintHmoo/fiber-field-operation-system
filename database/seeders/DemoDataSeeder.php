<?php

namespace Database\Seeders;

use App\Enums\JobStatus;
use App\Enums\PermissionName;
use App\Models\AreaCode;
use App\Models\InstallationRequest;
use App\Models\Lsp;
use App\Models\LspTeam;
use App\Models\LspTeamDailyCapacity;
use App\Models\LspUser;
use App\Models\OnCallRequest;
use App\Models\OperatorTeam;
use App\Models\Permission;
use App\Models\Status;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Seeder;

/**
 * Demo world for README / VERIFICATION.md. Run on a fresh database (migrate:fresh --seed)
 * so primary keys are predictable. Every demo account uses the password "password".
 *
 * Area coverage:
 *   Alpha Fiber Services (teams A1, A2) -> YGN-EAST, YGN-WEST
 *   Bravo Networks (team B1)            -> MDY-CENTRAL
 *   Yangon Ops (operator team)          -> YGN-EAST      => may act on Alpha only
 *   Mandalay Ops (operator team)        -> MDY-CENTRAL   => may act on Bravo only
 */
class DemoDataSeeder extends Seeder
{
    /**
     * Seed the demo LSPs, teams, users, operators and jobs.
     */
    public function run(): void
    {
        $areaCodes = collect(['YGN-EAST', 'YGN-WEST', 'MDY-CENTRAL'])
            ->mapWithKeys(fn (string $label) => [$label => AreaCode::query()->create(['label' => $label])]);

        $alpha = Lsp::query()->create(['name' => 'Alpha Fiber Services']);
        $alpha->areaCodes()->attach([$areaCodes['YGN-EAST']->id, $areaCodes['YGN-WEST']->id]);

        $bravo = Lsp::query()->create(['name' => 'Bravo Networks']);
        $bravo->areaCodes()->attach($areaCodes['MDY-CENTRAL']->id);

        $teamA1 = $alpha->teams()->create(['name' => 'Team A1']);
        $teamA2 = $alpha->teams()->create(['name' => 'Team A2']);
        $teamB1 = $bravo->teams()->create(['name' => 'Team B1']);

        // Team A1 has no capacity rows: it uses the default of 8, created on first booking.
        // Team A2 has a small cap of 2 every weekday so "capacity full" is easy to reach.
        foreach (range(0, 6) as $dayOfWeek) {
            LspTeamDailyCapacity::query()->create([
                'lsp_team_id' => $teamA2->id,
                'day_of_week' => $dayOfWeek,
                'max_slots' => 2,
            ]);
        }

        $this->createLspUser($teamA1, 'Team A1 Lead', 'a1.lead@alpha.test');
        $this->createLspUser($teamA2, 'Team A2 Lead', 'a2.lead@alpha.test');
        $this->createLspUser($teamB1, 'Team B1 Lead', 'b1.lead@bravo.test');

        $yangonOps = OperatorTeam::query()->create(['name' => 'Yangon Ops']);
        $yangonOps->areaCodes()->attach($areaCodes['YGN-EAST']->id);

        $mandalayOps = OperatorTeam::query()->create(['name' => 'Mandalay Ops']);
        $mandalayOps->areaCodes()->attach($areaCodes['MDY-CENTRAL']->id);

        $this->createOperator('Yangon Operator', 'ygn.operator@5bb.test')
            ->operatorTeams()->attach($yangonOps->id);

        $supervisor = $this->createOperator('Yangon Supervisor', 'ygn.supervisor@5bb.test');
        $supervisor->operatorTeams()->attach($yangonOps->id);
        $supervisor->permissions()->attach(
            Permission::query()->where('name', PermissionName::AppointmentOverride->value)->value('id'),
        );

        $this->createOperator('Mandalay Operator', 'mdy.operator@5bb.test')
            ->operatorTeams()->attach($mandalayOps->id);

        $this->createOperator('Super Admin', 'super.admin@5bb.test', isSuperAdmin: true);

        $this->seedJobs($alpha, $teamA1, $teamA2, $teamB1);
    }

    /**
     * Seed installation and on-call jobs covering the verification scenarios.
     */
    private function seedJobs(Lsp $alpha, LspTeam $teamA1, LspTeam $teamA2, LspTeam $teamB1): void
    {
        $timezone = config('app.business_timezone');

        // A day inside the 60-day booking window, 10:00 Yangon, converted to UTC for storage.
        $demoDay = CarbonImmutable::now($timezone)->addDays(7)->setTime(10, 0)->utc();

        // 2026-04-02 00:30 Yangon == 2026-04-01 18:00 UTC; must count on 2 April.
        $yangonBoundary = CarbonImmutable::parse('2026-04-02 00:30:00', $timezone)->utc();

        // installation_requests #1-#3: Team A1, unbooked.
        foreach (range(1, 3) as $ignored) {
            $this->createJob(InstallationRequest::class, $teamA1, JobStatus::Pending);
        }

        // #4-#6: Team A2 (cap 2), unbooked. Booking all three on one day: the third is rejected.
        foreach (range(1, 3) as $ignored) {
            $this->createJob(InstallationRequest::class, $teamA2, JobStatus::Pending);
        }

        // #7: Team B1, unbooked.
        $this->createJob(InstallationRequest::class, $teamB1, JobStatus::Pending);

        // #8: assigned to Alpha but no team yet -> booking returns 422.
        InstallationRequest::query()->create([
            'status_id' => $this->statusId(JobStatus::Pending),
            'current_lsp_id' => $alpha->id,
            'current_lsp_team_id' => null,
        ]);

        // #9-#11: Team A1 on the demo day. Complete counts; Cancelled and soft-deleted do not.
        $this->createJob(InstallationRequest::class, $teamA1, JobStatus::Complete, $demoDay);
        $this->createJob(InstallationRequest::class, $teamA1, JobStatus::Cancelled, $demoDay);
        $this->createJob(InstallationRequest::class, $teamA1, JobStatus::Pending, $demoDay)->delete();

        // #12: Team A1, Yangon timezone boundary.
        $this->createJob(InstallationRequest::class, $teamA1, JobStatus::Pending, $yangonBoundary);

        // on_call_requests #1: Team A1 on the demo day (shares the counter with installations).
        $this->createJob(OnCallRequest::class, $teamA1, JobStatus::Submitted, $demoDay);

        // #2: Team A1, unbooked. #3: Team B1, unbooked.
        $this->createJob(OnCallRequest::class, $teamA1, JobStatus::Pending);
        $this->createJob(OnCallRequest::class, $teamB1, JobStatus::Pending);
    }

    /**
     * @template TModel of InstallationRequest|OnCallRequest
     *
     * @param  class-string<TModel>  $modelClass
     * @return TModel
     */
    private function createJob(string $modelClass, LspTeam $team, JobStatus $status, ?CarbonImmutable $appointmentDate = null): Model
    {
        return $modelClass::query()->create([
            'status_id' => $this->statusId($status),
            'current_lsp_id' => $team->lsp_id,
            'current_lsp_team_id' => $team->id,
            'appointment_date' => $appointmentDate,
        ]);
    }

    private function createLspUser(LspTeam $team, string $name, string $email): LspUser
    {
        return LspUser::query()->create([
            'lsp_id' => $team->lsp_id,
            'lsp_team_id' => $team->id,
            'name' => $name,
            'email' => $email,
            'password' => 'password',
        ]);
    }

    private function createOperator(string $name, string $email, bool $isSuperAdmin = false): User
    {
        return User::query()->create([
            'name' => $name,
            'email' => $email,
            'password' => 'password',
            'is_super_admin' => $isSuperAdmin,
        ]);
    }

    private function statusId(JobStatus $status): int
    {
        return Status::query()->where('name', $status->value)->valueOrFail('id');
    }
}
