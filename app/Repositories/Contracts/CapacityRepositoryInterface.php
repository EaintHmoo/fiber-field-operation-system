<?php

namespace App\Repositories\Contracts;

use App\Models\LspTeamDailyCapacity;

interface CapacityRepositoryInterface
{
    /**
     * Read the capacity row for a team and weekday without locking.
     */
    public function find(int $lspTeamId, int $dayOfWeek): ?LspTeamDailyCapacity;

    /**
     * Return the capacity row locked FOR UPDATE, inserting it with the given default first if it is missing.
     *
     * Must be called inside a transaction; the lock is held until that transaction ends.
     *
     * @throws \LogicException when called outside a transaction
     */
    public function lockOrCreate(int $lspTeamId, int $dayOfWeek, int $defaultMaxSlots): LspTeamDailyCapacity;
}
