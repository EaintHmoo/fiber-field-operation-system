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
     * Lock first; insert the default row only when the locking read finds nothing.
     *
     * - No plain (non-locking) read may run before this lock: under REPEATABLE READ the first
     *   plain read fixes the transaction's snapshot, and a snapshot taken before the lock would
     *   hide bookings committed by the transaction that held it. The slot count that follows
     *   the lock is the first plain read, so it sees every booking committed before.
     * - insertOrIgnore() is not run unconditionally: on an existing row InnoDB takes a shared
     *   lock on the duplicate, and two bookings upgrading shared to exclusive deadlock.
     * - When the row is missing, racing locking reads take gap locks that can deadlock the
     *   inserts. That happens only until the row exists; the caller's transaction retries.
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
