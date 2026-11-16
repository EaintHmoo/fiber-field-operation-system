<?php

namespace App\Enums;

use App\Models\InstallationRequest;
use App\Models\OnCallRequest;

/**
 * Bookable job types. The value is stored in appointment_booking_logs.request_type and is the morph alias.
 */
enum RequestType: string
{
    case Installation = 'installation';
    case OnCall = 'oncall';

    /**
     * @return class-string<InstallationRequest|OnCallRequest>
     */
    public function modelClass(): string
    {
        return match ($this) {
            self::Installation => InstallationRequest::class,
            self::OnCall => OnCallRequest::class,
        };
    }

    /**
     * Morph map entries: alias => model class.
     *
     * @return array<string, class-string<InstallationRequest|OnCallRequest>>
     */
    public static function morphMap(): array
    {
        $map = [];

        foreach (self::cases() as $type) {
            $map[$type->value] = $type->modelClass();
        }

        return $map;
    }
}
