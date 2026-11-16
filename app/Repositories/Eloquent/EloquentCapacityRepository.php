<?php

namespace App\Repositories\Eloquent;

use App\Models\LspTeamDailyCapacity;
use App\Repositories\Contracts\CapacityRepositoryInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use LogicException;

class EloquentCapacityRepository implements CapacityRepositoryInterface
{
    public function find(int $lspTeamId, int $dayOfWeek): ?LspTeamDailyCapacity
    {
        return LspTeamDailyCapacity::query()
            ->where('lsp_team_id', $lspTeamId)
            ->where('day_of_week', $dayOfWeek)
            ->first();
    }

    /**
     * Lock first, insert only when missing. Running insertOrIgnore() unconditionally would make
     * InnoDB take a shared lock on the existing duplicate row; two concurrent bookings would then
     * both try to upgrade to an exclusive lock and deadlock on every contended booking. A race on
     * the very first insert can still deadlock; the caller's transaction retries resolve that.
     */
    public function lockOrCreate(int $lspTeamId, int $dayOfWeek, int $defaultMaxSlots): LspTeamDailyCapacity
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Capacity rows must be locked inside a database transaction.');
        }

        $capacity = $this->lockedQuery($lspTeamId, $dayOfWeek)->first();

        if ($capacity !== null) {
            return $capacity;
        }

        LspTeamDailyCapacity::query()->insertOrIgnore([
            'lsp_team_id' => $lspTeamId,
            'day_of_week' => $dayOfWeek,
            'max_slots' => $defaultMaxSlots,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $this->lockedQuery($lspTeamId, $dayOfWeek)->firstOrFail();
    }

    /**
     * @return Builder<LspTeamDailyCapacity>
     */
    private function lockedQuery(int $lspTeamId, int $dayOfWeek): Builder
    {
        return LspTeamDailyCapacity::query()
            ->where('lsp_team_id', $lspTeamId)
            ->where('day_of_week', $dayOfWeek)
            ->lockForUpdate();
    }
}
