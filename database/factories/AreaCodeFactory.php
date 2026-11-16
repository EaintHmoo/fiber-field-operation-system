<?php

namespace Database\Factories;

use App\Models\AreaCode;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AreaCode>
 */
class AreaCodeFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'label' => strtoupper(fake()->unique()->bothify('???-##')),
        ];
    }
}
