<?php

namespace Tests\Feature\Repositories\Eloquent;

use App\Models\LspTeam;
use App\Models\LspTeamDailyCapacity;
use App\Repositories\Contracts\CapacityRepositoryInterface;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\TestCase;

class EloquentCapacityRepositoryTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_lock_or_create_inserts_default_row_when_missing(): void
    {
        $team = LspTeam::factory()->create();

        $capacity = DB::transaction(fn () => $this->repository()->lockOrCreate($team->id, 4, 8));

        $this->assertSame(8, $capacity->max_slots);
        $this->assertDatabaseHas('lsp_team_daily_capacities', [
            'lsp_team_id' => $team->id,
            'day_of_week' => 4,
            'max_slots' => 8,
        ]);
    }

    public function test_lock_or_create_returns_configured_row_without_overwriting_it(): void
    {
        $capacity = LspTeamDailyCapacity::factory()->create(['day_of_week' => 4, 'max_slots' => 2]);

        $locked = DB::transaction(fn () => $this->repository()->lockOrCreate($capacity->lsp_team_id, 4, 8));

        $this->assertTrue($capacity->is($locked));
        $this->assertSame(2, $locked->max_slots);
    }

    public function test_lock_or_create_does_not_duplicate_row_on_repeat_calls(): void
    {
        $team = LspTeam::factory()->create();

        DB::transaction(fn () => $this->repository()->lockOrCreate($team->id, 4, 8));
        DB::transaction(fn () => $this->repository()->lockOrCreate($team->id, 4, 8));

        $this->assertDatabaseCount('lsp_team_daily_capacities', 1);
    }

    public function test_lock_or_create_refuses_to_run_outside_a_transaction(): void
    {
        // No records are created: any query would make LazilyRefreshDatabase open its wrapping
        // transaction, and the guard throws before the repository touches the database.
        $this->expectException(LogicException::class);

        $this->repository()->lockOrCreate(lspTeamId: 1, dayOfWeek: 4, defaultMaxSlots: 8);
    }

    public function test_find_returns_null_when_team_has_no_row_for_weekday(): void
    {
        $capacity = LspTeamDailyCapacity::factory()->create(['day_of_week' => 1]);

        $this->assertNull($this->repository()->find($capacity->lsp_team_id, 2));
    }

    private function repository(): CapacityRepositoryInterface
    {
        return $this->app->make(CapacityRepositoryInterface::class);
    }
}
