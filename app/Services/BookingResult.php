<?php

namespace App\Services;

use App\Enums\BookingAction;
use App\Models\InstallationRequest;
use App\Models\OnCallRequest;

final readonly class BookingResult
{
    /**
     * @param  BookingAction|null  $action  Null when the booking was idempotent (same job, same business day).
     */
    public function __construct(
        public InstallationRequest|OnCallRequest $job,
        public ?BookingAction $action,
        public CapacitySnapshot $capacity,
    ) {}

    public function wasIdempotent(): bool
    {
        return $this->action === null;
    }
}
