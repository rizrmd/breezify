<div>
    <!-- Header -->
    <div class="flex items-center justify-between mb-6">
        <h3 class="text-lg font-medium text-white">Project Access for {{ $member->name }}</h3>
    </div>

    <!-- Access Status Indicator -->
    <div class="mb-6 p-4 rounded-lg border @if($restrictAccess) border-warning-200 bg-warning-50 dark:bg-warning-900/20 @else border-success-200 bg-success-50 dark:bg-success-900/20 @endif">
        @if($restrictAccess)
            <div class="flex items-start gap-3">
                <svg class="w-5 h-5 text-warning-600 dark:text-warning-400 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
                </svg>
                <div>
                    <p class="font-medium text-warning-800 dark:text-warning-200">Restricted Access</p>
                    <p class="text-sm text-warning-700 dark:text-warning-300 mt-1">
                        This user can only access {{ count($selectedProjectIds) }} of {{ $allProjects->count() }} team projects.
                    </p>
                </div>
            </div>
        @else
            <div class="flex items-start gap-3">
                <svg class="w-5 h-5 text-success-600 dark:text-success-400 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                </svg>
                <div>
                    <p class="font-medium text-success-800 dark:text-success-200">Full Access</p>
                    <p class="text-sm text-success-700 dark:text-success-300 mt-1">
                        This user can access all {{ $allProjects->count() }} team projects.
                    </p>
                </div>
            </div>
        @endif
    </div>

    @if(! $member->isAdminOfTeam($team->id))
        <div class="mb-6 flex items-center justify-between gap-4">
            <div>
                <p class="text-sm font-medium text-gray-200">Enable project restrictions</p>
                <p class="text-xs text-gray-400 mt-1">Turn on to select which projects this member may access.</p>
            </div>
            <label class="relative inline-flex items-center cursor-pointer">
                <input type="checkbox" wire:model.live="restrictAccess" class="sr-only peer">
                <div class="w-11 h-6 bg-gray-200 rounded-full transition peer-checked:bg-blue-600 dark:bg-gray-700"></div>
                <span class="absolute left-1 top-1 h-4 w-4 bg-white rounded-full transition peer-checked:translate-x-5"></span>
            </label>

            </label>
        </div>
    @endif

    <!-- Project Selection Form -->
    <form wire:submit="save">
        @php
            $restrictionDisabled = !$restrictAccess || $member->isAdminOfTeam($team->id);
        @endphp

        @if(!$restrictAccess && ! $member->isAdminOfTeam($team->id))
            <div class="mb-4 text-sm text-gray-400">
                Restrictions are disabled. Enable them above to choose specific projects.
            </div>
        @endif

        <div class="space-y-3">
            @foreach($allProjects as $project)
                <label class="flex items-center p-4 border rounded-lg cursor-pointer hover:bg-gray-50 dark:hover:bg-gray-800 transition-colors @if(in_array($project->id, $selectedProjectIds)) bg-blue-50 dark:bg-blue-900/20 border-blue-200 dark:border-blue-800 @endif @if($restrictionDisabled) opacity-60 cursor-not-allowed @endif">
                    <input
                        type="checkbox"
                        value="{{ $project->id }}"
                        wire:model.live="selectedProjectIds"
                        @if($restrictionDisabled)
                            disabled
                        @endif
                        class="w-4 h-4 text-blue-600 rounded focus:ring-blue-500 dark:bg-gray-700 dark:border-gray-600"
                    />

                    <div class="ml-4 flex-1">
                        <div class="font-medium text-gray-900 dark:text-white">{{ $project->name }}</div>
                        @if($project->description)
                            <div class="text-sm text-gray-500 dark:text-gray-400 mt-0.5">{{ $project->description }}</div>
                        @endif
                        <div class="text-xs text-gray-400 dark:text-gray-500 mt-1">
                            {{ $project->environments->count() }} environment(s)
                        </div>
                    </div>

                    @if(in_array($project->id, $selectedProjectIds))
                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-200">
                            Access Granted
                        </span>
                    @endif
                </label>
            @endforeach

            @if($allProjects->isEmpty())
                <div class="text-center py-12 text-gray-500 dark:text-gray-400">
                    <svg class="mx-auto h-12 w-12 text-gray-400 dark:text-gray-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"/>
                    </svg>
                    <p class="mt-2">No projects found in this team.</p>
                </div>
            @endif
        </div>

        @if(!$member->isAdminOfTeam($team->id) && $allProjects->isNotEmpty())
            <div class="mt-6 flex items-center justify-between">
                <div class="text-sm text-gray-500 dark:text-gray-400">
                    @if($restrictAccess)
                        <span class="font-medium">{{ count($selectedProjectIds) }}</span> of {{ $allProjects->count() }} projects selected
                    @else
                        Restrictions disabled
                    @endif
                </div>
                <x-forms.button type="submit">
                    Save Project Access
                </x-forms.button>
            </div>
        @endif
    </form>

    <!-- Admin Notice -->
    @if($member->isAdminOfTeam($team->id))
        <div class="mt-6 p-4 bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-800 rounded-lg">
            <div class="flex">
                <svg class="w-5 h-5 text-blue-400 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                </svg>
                <div class="ml-3">
                    <p class="text-sm text-blue-800 dark:text-blue-200">
                        <strong>Team admins and owners</strong> automatically have access to all projects and cannot be restricted.
                    </p>
                </div>
            </div>
        </div>
    @endif
</div>
