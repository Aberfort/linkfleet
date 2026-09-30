<?php

namespace Database\Factories;

use App\Models\Click;
use App\Models\Link;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Click>
 */
class ClickFactory extends Factory
{
    public function definition(): array
    {
        return [
            'link_id' => Link::factory(),
            'token' => Str::random(24),
            'ip_hash' => hash('sha256', fake()->ipv4()),
            'referrer' => fake()->domainName(),
            'browser' => 'Chrome',
            'browser_version' => '129.0',
            'platform' => 'Windows',
            'device_type' => 'desktop',
        ];
    }
}
