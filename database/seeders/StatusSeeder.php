<?php

namespace Database\Seeders;

use App\Enums\JobStatus;
use App\Models\Status;
use Illuminate\Database\Seeder;

class StatusSeeder extends Seeder
{
    /**
     * Seed the job statuses required by the booking rules.
     */
    public function run(): void
    {
        foreach (JobStatus::cases() as $status) {
            Status::query()->firstOrCreate(['name' => $status->value]);
        }
    }
}
