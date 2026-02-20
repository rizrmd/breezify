<?php

namespace Database\Factories;

use App\Models\Environment;
use App\Models\Server;
use App\Models\Service;
use App\Models\StandaloneDocker;
use Illuminate\Database\Eloquent\Factories\Factory;

class ServiceFactory extends Factory
{
    protected $model = Service::class;

    public function definition(): array
    {
        return [
            'name' => fake()->unique()->domainWord().'-service',
            'description' => fake()->optional()->sentence(),
            'docker_compose_raw' => "version: '3'\nservices:\n  app:\n    image: nginx:alpine",
            'docker_compose' => "version: '3'\nservices:\n  app:\n    image: nginx:alpine",
            'environment_id' => Environment::factory(),
            'connect_to_docker_network' => false,
            'service_type' => 'compose',
        ];
    }

    public function configure(): static
    {
        return $this->afterMaking(function (Service $service) {
            if (! $service->environment_id) {
                $environment = Environment::factory()->create();
                $service->environment()->associate($environment);
            } else {
                $service->setRelation('environment', $service->environment ?? Environment::find($service->environment_id));
            }

            if (! $service->server_id) {
                $teamId = $service->environment->project->team_id;
                $server = Server::factory()->create(['team_id' => $teamId]);
                $service->server_id = $server->id;
            } else {
                $service->setRelation('server', $service->server ?? Server::find($service->server_id));
            }

            if (! $service->destination_id) {
                $destination = StandaloneDocker::factory()->create(['server_id' => $service->server_id]);
                $service->destination_id = $destination->id;
                $service->destination_type = $destination->getMorphClass();
            }
        });
    }
}
