<?php

namespace Database\Factories;

use App\Models\DnsSetting;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DnsSetting>
 */
class DnsSettingFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'infrastructure_domain' => null,
            'nameservers' => [['hostname' => '', 'ip' => ''], ['hostname' => '', 'ip' => '']],
        ];
    }
}
