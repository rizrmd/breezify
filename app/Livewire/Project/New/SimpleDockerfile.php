<?php

namespace App\Livewire\Project\New;

use App\Models\Application;
use App\Models\GithubApp;
use App\Models\Project;
use App\Models\StandaloneDocker;
use App\Models\SwarmDocker;
use Livewire\Component;
use Visus\Cuid2\Cuid2;

class SimpleDockerfile extends Component
{
    public string $dockerfile = '';

    public string $limitsCpus = '0';

    public string $limitsMemory = '0';

    public float $maxCpus = 1.0;

    public float $maxMemoryGb = 1.0;

    public array $parameters;

    public array $query;

    public function mount()
    {
        $this->parameters = get_route_parameters();
        $this->query = request()->query();
        $this->resolveResourceCaps();
        if (isDev()) {
            $this->dockerfile = 'FROM nginx
EXPOSE 80
CMD ["nginx", "-g", "daemon off;"]
';
        }
    }

    public function submit()
    {
        $this->validate([
            'dockerfile' => 'required',
            'limitsCpus' => 'required|numeric|min:0|max:'.$this->maxCpus,
            'limitsMemory' => 'required|numeric|min:0|max:'.$this->maxMemoryGb,
        ]);
        $destination_uuid = $this->query['destination'];
        $destination = StandaloneDocker::where('uuid', $destination_uuid)->first();
        if (! $destination) {
            $destination = SwarmDocker::where('uuid', $destination_uuid)->first();
        }
        if (! $destination) {
            throw new \Exception('Destination not found. What?!');
        }
        $destination_class = $destination->getMorphClass();

        $project = Project::where('uuid', $this->parameters['project_uuid'])->first();
        $environment = $project->load(['environments'])->environments->where('uuid', $this->parameters['environment_uuid'])->first();

        $port = get_port_from_dockerfile($this->dockerfile);
        if (! $port) {
            $port = 80;
        }
        $application = Application::create([
            'name' => 'dockerfile-'.new Cuid2,
            'repository_project_id' => 0,
            'git_repository' => 'coollabsio/coolify',
            'git_branch' => 'main',
            'build_pack' => 'dockerfile',
            'dockerfile' => $this->dockerfile,
            'limits_cpus' => (string) $this->limitsCpus,
            'limits_memory' => $this->formatMemoryLimit($this->limitsMemory),
            'ports_exposes' => $port,
            'environment_id' => $environment->id,
            'destination_id' => $destination->id,
            'destination_type' => $destination_class,
            'health_check_enabled' => false,
            'source_id' => 0,
            'source_type' => GithubApp::class,
        ]);

        $fqdn = generateUrl(server: $destination->server, random: $application->uuid);
        $application->update([
            'name' => 'dockerfile-'.$application->uuid,
            'fqdn' => $fqdn,
        ]);

        $application->parseHealthcheckFromDockerfile(dockerfile: $this->dockerfile, isInit: true);

        return redirect()->route('project.application.configuration', [
            'application_uuid' => $application->uuid,
            'environment_uuid' => $environment->uuid,
            'project_uuid' => $project->uuid,
        ]);
    }

    private function resolveResourceCaps(): void
    {
        $destinationUuid = data_get($this->query, 'destination');
        if (! $destinationUuid) {
            return;
        }
        $destination = StandaloneDocker::where('uuid', $destinationUuid)->first() ?? SwarmDocker::where('uuid', $destinationUuid)->first();
        if (! $destination) {
            return;
        }
        $limits = resolve_server_resource_limits($destination->server);
        $this->maxCpus = (float) data_get($limits, 'cpus', 1);
        $this->maxMemoryGb = (float) data_get($limits, 'memory_gb', 1);
    }

    private function formatMemoryLimit(string $value): string
    {
        $normalized = (float) $value;
        if ($normalized <= 0) {
            return '0';
        }

        $formatted = rtrim(rtrim(number_format($normalized, 2, '.', ''), '0'), '.');

        return $formatted.'g';
    }
}
