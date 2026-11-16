<?php

namespace App\Services;

use Carbon\CarbonImmutable;

/**
 * A team's capacity on one business (Asia/Yangon) calendar day.
 */
final readonly class CapacitySnapshot
{
    public function __construct(
        public CarbonImmutable $date,
        public int $maxSlots,
        public int $usedSlots,
    ) {}

    /**
     * Never negative: an override can push used slots above the maximum.
     */
    public function remainingSlots(): int
    {
        return max(0, $this->maxSlots - $this->usedSlots);
    }

    /**
     * @return array{date: string, day_of_week: int, max_slots: int, used_slots: int, remaining_slots: int}
     */
    public function toArray(): array
    {
        return [
            'date' => $this->date->toDateString(),
            'day_of_week' => $this->date->dayOfWeek,
            'max_slots' => $this->maxSlots,
            'used_slots' => $this->usedSlots,
            'remaining_slots' => $this->remainingSlots(),
        ];
    }
}
