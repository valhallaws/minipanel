<?php

namespace Database\Factories;

use App\Models\SiteRepository;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SiteRepository>
 */
class SiteRepositoryFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'key_token' => fake()->uuid(),
            'name' => fake()->unique()->slug(2),
            'url' => 'git@example.com:team/project.git',
            'directory' => fake()->unique()->slug(2),
            'status' => 'configured',
            'prepare_project' => true,
        ];
    }
}
