<?php

namespace Database\Factories;

use App\Models\PrivateKey;
use App\Models\Server;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class ServerFactory extends Factory
{
    protected $model = Server::class;

    public function definition(): array
    {
        return [
            'uuid' => (string) Str::uuid(),
            'name' => fake()->unique()->domainWord().'-server',
            'description' => fake()->optional()->sentence(),
            'ip' => fake()->unique()->ipv4(),
            'port' => 22,
            'user' => 'root',
            'team_id' => Team::factory(),
        ];
    }

    public function configure(): static
    {
        return $this->afterMaking(function (Server $server) {
            if (! $server->team_id) {
                $server->team_id = Team::factory()->create()->id;
            }

            if (! $server->private_key_id) {
                $server->private_key_id = PrivateKey::factory()->create([
                    'team_id' => $server->team_id,
                ])->id;
            }

            if (! $server->uuid) {
                $server->uuid = (string) Str::uuid();
            }
        });
    }
}
