<?php

namespace App\Enums;

/**
 * Status names the booking rules depend on. Stored as statuses.name; the table may hold additional rows.
 */
enum JobStatus: string
{
    case Pending = 'Pending';
    case Submitted = 'Submitted';
    case OnGoing = 'On Going';
    case Complete = 'Complete';
    case InProgress = 'In-Progress';
    case Cancelled = 'Cancelled';
    case Termination = 'Termination';
    case RefundRequest = 'Refund Request';
    case Refund = 'Refund';

    /**
     * Whether a job in this status uses one of its team's daily capacity slots.
     */
    public function consumesSlot(): bool
    {
        return ! in_array($this, self::nonSlotConsuming(), true);
    }

    /**
     * Statuses whose jobs are excluded from capacity counting.
     *
     * @return list<self>
     */
    public static function nonSlotConsuming(): array
    {
        return [self::Cancelled, self::Termination, self::RefundRequest, self::Refund];
    }
}
