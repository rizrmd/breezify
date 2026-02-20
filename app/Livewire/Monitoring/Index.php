<?php

namespace App\Livewire\Monitoring;

use Livewire\Component;

class Index extends Component
{
    public array $resources = [];

    public function mount(): void
    {
        $teamId = currentTeam()?->id;
        if ($teamId === null) {
            abort(403);
        }

        // Root Team (id=0) can see everything.
        $this->resources = $teamId === 0
            ? $this->loadAllTeamsResources()
            : $this->loadTeamResources($teamId);
    }

    private function loadTeamResources(int $teamId): array
    {
        return [
            'servers' => \App\Models\Server::query()->accessibleByTeam($teamId)->where('id', '!=', 0)->get(['id', 'uuid', 'name'])->toArray(),
            'projects' => \App\Models\Project::query()->whereTeamId($teamId)->orderByRaw('LOWER(name)')->get(['id', 'uuid', 'name'])->toArray(),
        ];
    }

    private function loadAllTeamsResources(): array
    {
        return [
            'servers' => \App\Models\Server::query()->where('id', '!=', 0)->get(['id', 'uuid', 'name', 'team_id'])->toArray(),
            'projects' => \App\Models\Project::query()->get(['id', 'uuid', 'name', 'team_id'])->toArray(),
        ];
    }

    public function render()
    {
        return view('livewire.monitoring.index');
    }
}
