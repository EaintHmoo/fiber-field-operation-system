<?php

namespace App\Enums;

/**
 * Value of appointment_booking_logs.action.
 */
enum BookingAction: string
{
    case Book = 'book';
    case Reschedule = 'reschedule';
    case Override = 'override';
}
