<?php

namespace Database\Seeders;

use App\Enums\PermissionName;
use App\Models\Permission;
use Illuminate\Database\Seeder;

class PermissionSeeder extends Seeder
{
    /**
     * Seed the operator permissions.
     */
    public function run(): void
    {
        Permission::query()->firstOrCreate(['name' => PermissionName::AppointmentOverride->value]);
    }
}
