<div>
    <x-slot:title>
        Monitoring | Coolify
    </x-slot>

    <div class="flex items-center gap-2">
        <h1>Monitoring</h1>
    </div>
    <div class="subtitle">
        @if (currentTeam()?->id === 0)
            Viewing resources across all teams.
        @else
            Viewing resources for your current team.
        @endif
    </div>

    <div class="grid gap-4 lg:grid-cols-2">
        <div class="coolbox">
            <div class="font-bold dark:text-white">Servers</div>
            <div class="description">{{ count(data_get($resources, 'servers', [])) }} total</div>

            <div class="mt-3 space-y-2">
                @forelse (data_get($resources, 'servers', []) as $server)
                    <a class="block hover:underline" href="{{ route('server.show', ['server_uuid' => data_get($server, 'uuid')]) }}" {{ wireNavigate() }}>
                        {{ data_get($server, 'name') }}
                        @if (currentTeam()?->id === 0)
                            <span class="text-xs opacity-70">(team_id: {{ data_get($server, 'team_id') }})</span>
                        @endif
                    </a>
                @empty
                    <div class="description">No servers found.</div>
                @endforelse
            </div>
        </div>

        <div class="coolbox">
            <div class="font-bold dark:text-white">Projects</div>
            <div class="description">{{ count(data_get($resources, 'projects', [])) }} total</div>

            <div class="mt-3 space-y-2">
                @forelse (data_get($resources, 'projects', []) as $project)
                    <a class="block hover:underline" href="{{ route('project.show', ['project_uuid' => data_get($project, 'uuid')]) }}" {{ wireNavigate() }}>
                        {{ data_get($project, 'name') }}
                        @if (currentTeam()?->id === 0)
                            <span class="text-xs opacity-70">(team_id: {{ data_get($project, 'team_id') }})</span>
                        @endif
                    </a>
                @empty
                    <div class="description">No projects found.</div>
                @endforelse
            </div>
        </div>
    </div>
</div>
