<?php

namespace Database\Factories;

use App\Models\Server;
use App\Models\StandaloneDocker;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class StandaloneDockerFactory extends Factory
{
    protected $model = StandaloneDocker::class;

    public function definition(): array
    {
        return [
            'uuid' => (string) Str::uuid(),
            'name' => fake()->unique()->domainWord().'-docker',
            'network' => 'coolify-network-'.Str::random(8),
            'server_id' => Server::factory(),
        ];
    }
}
