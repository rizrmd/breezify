<?php

namespace Database\Factories;

use App\Models\Application;
use App\Models\Environment;
use App\Models\Server;
use App\Models\StandaloneDocker;
use Illuminate\Database\Eloquent\Factories\Factory;

class ApplicationFactory extends Factory
{
    protected $model = Application::class;

    public function definition(): array
    {
        return [
            'name' => fake()->unique()->domainWord().'-app',
            'description' => fake()->optional()->sentence(),
            'git_repository' => 'https://github.com/'.fake()->userName().'/'.fake()->slug().'.git',
            'git_branch' => 'main',
            'git_full_url' => null,
            'build_pack' => 'nixpacks',

            'ports_exposes' => '3000',
            'environment_id' => Environment::factory(),
            'install_command' => null,
            'build_command' => null,
            'start_command' => null,
            'base_directory' => '/',
            'status' => 'running:healthy',
        ];
    }

    public function configure(): static
    {
        return $this->afterMaking(function (Application $application) {
            if (! $application->environment_id) {
                $environment = Environment::factory()->create();
                $application->environment()->associate($environment);
            } else {
                $application->setRelation('environment', $application->environment ?? Environment::find($application->environment_id));
            }

            if (! $application->destination_id) {
                $teamId = $application->environment->project->team_id;
                $server = Server::factory()->create(['team_id' => $teamId]);
                $destination = StandaloneDocker::factory()->create(['server_id' => $server->id]);
                $application->destination_id = $destination->id;
                $application->destination_type = $destination->getMorphClass();
            }
        });
    }
}
