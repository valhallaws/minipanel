<?php

namespace Database\Factories;

use App\Models\SiteDatabaseOperation;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SiteDatabaseOperation>
 */
class SiteDatabaseOperationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'action' => 'export',
            'resource_id' => 1,
            'resource_name' => 'example',
            'token' => bin2hex(random_bytes(16)),
        ];
    }
}
