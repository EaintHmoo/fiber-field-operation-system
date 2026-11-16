<?php

namespace Tests\Feature\Services;

use App\Enums\PermissionName;
use App\Enums\RequestType;
use App\Models\AreaCode;
use App\Models\Lsp;
use App\Models\LspTeam;
use App\Models\LspUser;
use App\Models\OperatorTeam;
use App\Models\Permission;
use App\Models\User;
use App\Services\AuthorizationService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AuthorizationServiceTest extends TestCase
{
    use LazilyRefreshDatabase;

    #[DataProvider('requestTypes')]
    public function test_lsp_user_may_book_job_assigned_to_own_team(RequestType $type): void
    {
        $team = LspTeam::factory()->create();
        $job = $type->modelClass()::factory()->forTeam($team)->create();
        $lspUser = LspUser::factory()->forTeam($team)->create();

        $this->assertTrue($this->service()->canBookJob($lspUser, $job));
    }

    #[DataProvider('requestTypes')]
    public function test_lsp_user_may_not_book_job_of_team_in_another_lsp(RequestType $type): void
    {
        $job = $type->modelClass()::factory()->forTeam(LspTeam::factory()->create())->create();
        $lspUser = LspUser::factory()->forTeam(LspTeam::factory()->create())->create();

        $this->assertFalse($this->service()->canBookJob($lspUser, $job));
    }

    #[DataProvider('requestTypes')]
    public function test_lsp_user_may_not_book_job_of_sibling_team_in_same_lsp(RequestType $type): void
    {
        $lsp = Lsp::factory()->create();
        $job = $type->modelClass()::factory()->forTeam(LspTeam::factory()->for($lsp)->create())->create();
        $lspUser = LspUser::factory()->forTeam(LspTeam::factory()->for($lsp)->create())->create();

        $this->assertFalse($this->service()->canBookJob($lspUser, $job));
    }

    #[DataProvider('requestTypes')]
    public function test_lsp_user_may_not_book_job_without_team_even_in_own_lsp(RequestType $type): void
    {
        $lspUser = LspUser::factory()->create();
        $job = $type->modelClass()::factory()->withoutTeam()->create(['current_lsp_id' => $lspUser->lsp_id]);

        $this->assertFalse($this->service()->canBookJob($lspUser, $job));
    }

    #[DataProvider('requestTypes')]
    public function test_operator_may_book_job_when_area_code_is_shared(RequestType $type): void
    {
        $areaCode = AreaCode::factory()->create();
        $team = $this->teamInArea($areaCode);
        $job = $type->modelClass()::factory()->forTeam($team)->create();

        $this->assertTrue($this->service()->canBookJob($this->operatorCovering($areaCode), $job));
    }

    #[DataProvider('requestTypes')]
    public function test_operator_may_not_book_job_when_no_area_code_is_shared(RequestType $type): void
    {
        $team = $this->teamInArea(AreaCode::factory()->create());
        $job = $type->modelClass()::factory()->forTeam($team)->create();

        $operator = $this->operatorCovering(AreaCode::factory()->create());

        $this->assertFalse($this->service()->canBookJob($operator, $job));
    }

    #[DataProvider('requestTypes')]
    public function test_operator_without_operator_teams_may_not_book_job(RequestType $type): void
    {
        $job = $type->modelClass()::factory()->forTeam($this->teamInArea(AreaCode::factory()->create()))->create();

        $this->assertFalse($this->service()->canBookJob(User::factory()->create(), $job));
    }

    #[DataProvider('requestTypes')]
    public function test_super_admin_may_book_any_job_without_area_codes(RequestType $type): void
    {
        $job = $type->modelClass()::factory()->create();

        $this->assertTrue($this->service()->canBookJob(User::factory()->superAdmin()->create(), $job));
    }

    #[DataProvider('requestTypes')]
    public function test_operator_check_uses_job_lsp_when_job_has_no_team(RequestType $type): void
    {
        $areaCode = AreaCode::factory()->create();
        $lsp = Lsp::factory()->create();
        $lsp->areaCodes()->attach($areaCode);
        $job = $type->modelClass()::factory()->withoutTeam()->create(['current_lsp_id' => $lsp->id]);

        $this->assertTrue($this->service()->canBookJob($this->operatorCovering($areaCode), $job));
        $this->assertFalse($this->service()->canBookJob($this->operatorCovering(AreaCode::factory()->create()), $job));
    }

    #[DataProvider('requestTypes')]
    public function test_only_super_admin_may_book_job_without_lsp_or_team(RequestType $type): void
    {
        $areaCode = AreaCode::factory()->create();
        $this->teamInArea($areaCode);
        $job = $type->modelClass()::factory()->withoutTeam()->create(['current_lsp_id' => null]);

        $this->assertFalse($this->service()->canBookJob($this->operatorCovering($areaCode), $job));
        $this->assertTrue($this->service()->canBookJob(User::factory()->superAdmin()->create(), $job));
    }

    public function test_operator_reaches_lsp_through_any_of_several_operator_teams(): void
    {
        $sharedAreaCode = AreaCode::factory()->create();
        $team = $this->teamInArea($sharedAreaCode);

        $operator = $this->operatorCovering(AreaCode::factory()->create());
        $secondOperatorTeam = OperatorTeam::factory()->create();
        $secondOperatorTeam->areaCodes()->attach($sharedAreaCode);
        $operator->operatorTeams()->attach($secondOperatorTeam);

        $this->assertTrue($this->service()->canAccessTeam($operator, $team));
    }

    public function test_area_code_of_another_operator_team_does_not_grant_access(): void
    {
        $areaCode = AreaCode::factory()->create();
        $team = $this->teamInArea($areaCode);
        $this->operatorCovering($areaCode);

        $outsider = $this->operatorCovering(AreaCode::factory()->create());

        $this->assertFalse($this->service()->canAccessTeam($outsider, $team));
    }

    public function test_lsp_user_may_access_only_own_team(): void
    {
        $lspUser = LspUser::factory()->create();
        $siblingTeam = LspTeam::factory()->for($lspUser->lsp)->create();

        $this->assertTrue($this->service()->canAccessTeam($lspUser, $lspUser->team));
        $this->assertFalse($this->service()->canAccessTeam($lspUser, $siblingTeam));
    }

    public function test_operator_may_access_team_only_when_area_code_is_shared(): void
    {
        $areaCode = AreaCode::factory()->create();
        $team = $this->teamInArea($areaCode);

        $this->assertTrue($this->service()->canAccessTeam($this->operatorCovering($areaCode), $team));
        $this->assertFalse($this->service()->canAccessTeam($this->operatorCovering(AreaCode::factory()->create()), $team));
        $this->assertTrue($this->service()->canAccessTeam(User::factory()->superAdmin()->create(), $team));
    }

    public function test_operator_with_override_permission_may_override_capacity(): void
    {
        $operator = User::factory()->create();
        $operator->permissions()->attach(Permission::factory()->create(['name' => PermissionName::AppointmentOverride->value]));

        $this->assertTrue($this->service()->canOverrideCapacity($operator));
    }

    public function test_operator_without_override_permission_may_not_override_capacity(): void
    {
        $operator = User::factory()->create();
        $operator->permissions()->attach(Permission::factory()->create());

        $this->assertFalse($this->service()->canOverrideCapacity($operator));
    }

    public function test_super_admin_without_override_permission_may_not_override_capacity(): void
    {
        $this->assertFalse($this->service()->canOverrideCapacity(User::factory()->superAdmin()->create()));
    }

    public function test_lsp_user_may_not_override_capacity(): void
    {
        $this->assertFalse($this->service()->canOverrideCapacity(LspUser::factory()->create()));
    }

    /**
     * @return array<string, array{RequestType}>
     */
    public static function requestTypes(): array
    {
        return collect(RequestType::cases())
            ->mapWithKeys(fn (RequestType $type): array => [$type->value => [$type]])
            ->all();
    }

    private function teamInArea(AreaCode $areaCode): LspTeam
    {
        $team = LspTeam::factory()->create();
        $team->lsp->areaCodes()->attach($areaCode);

        return $team;
    }

    private function operatorCovering(AreaCode $areaCode): User
    {
        $operatorTeam = OperatorTeam::factory()->create();
        $operatorTeam->areaCodes()->attach($areaCode);

        $operator = User::factory()->create();
        $operator->operatorTeams()->attach($operatorTeam);

        return $operator;
    }

    private function service(): AuthorizationService
    {
        return $this->app->make(AuthorizationService::class);
    }
}
