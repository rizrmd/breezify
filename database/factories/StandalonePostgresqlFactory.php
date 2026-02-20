<?php

namespace Database\Factories;

use App\Models\Environment;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\StandalonePostgresql;
use Illuminate\Database\Eloquent\Factories\Factory;

class StandalonePostgresqlFactory extends Factory
{
    protected $model = StandalonePostgresql::class;

    public function definition(): array
    {
        return [
            'name' => fake()->unique()->domainWord().'_db',
            'description' => fake()->optional()->sentence(),
            'postgres_password' => fake()->password(12),
            'postgres_db' => fake()->domainWord().'_db',
            'status' => 'running:healthy',
            'image' => 'postgres:15-alpine',
            'environment_id' => Environment::factory(),
        ];
    }

    public function configure(): static
    {
        return $this->afterMaking(function (StandalonePostgresql $database) {
            if (! $database->environment_id) {
                $environment = Environment::factory()->create();
                $database->environment()->associate($environment);
            } else {
                $database->setRelation('environment', $database->environment ?? Environment::find($database->environment_id));
            }

            if (! $database->destination_id) {
                $teamId = $database->environment->project->team_id;
                $server = Server::factory()->create(['team_id' => $teamId]);
                $destination = StandaloneDocker::factory()->create(['server_id' => $server->id]);
                $database->destination_id = $destination->id;
                $database->destination_type = $destination->getMorphClass();
            }
        });
    }
}
