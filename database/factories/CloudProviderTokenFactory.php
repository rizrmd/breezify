<?php

namespace Database\Factories;

use App\Models\CloudProviderToken;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class CloudProviderTokenFactory extends Factory
{
    protected $model = CloudProviderToken::class;

    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'provider' => fake()->randomElement(['hetzner', 'aws', 'do']),
            'token' => Str::random(40),
            'name' => fake()->optional()->words(2, true),
        ];
    }
}
