<?php

namespace App\Livewire\Team;

use App\Models\Team;
use App\Models\User;
use App\Models\Project;
use Illuminate\Support\Collection;
use Livewire\Attributes\Validate;
use Livewire\Component;

class TeamMemberProjectAccess extends Component
{
    public Team $team;
    public User $member;
    public Collection $allProjects;
    public array $selectedProjectIds = [];

    public function mount(): void
    {
        $this->authorize('update', $this->team);

        $this->allProjects = Project::where('team_id', $this->team->id)->get();
        $this->selectedProjectIds = $this->member->restrictedProjectIds($this->team->id);
    }

    public function save(): void
    {
        $this->authorize('update', $this->team);

        // Prevent restricting admins/owners
        if ($this->member->isAdminOfTeam($this->team->id)) {
            $this->dispatch('error', 'Cannot restrict admin/owner access.');
            return;
        }

        // Validate all selected projects belong to the team
        $validIds = $this->allProjects->pluck('id')->toArray();
        $this->selectedProjectIds = array_intersect($this->selectedProjectIds, $validIds);

        // Save to configuration file
        setAllowedProjects($this->team->id, $this->member->id, $this->selectedProjectIds);

        $this->dispatch('success', 'Project access updated successfully.');
    }

    public function render(): \Illuminate\View\View
    {
        return view('livewire.team.team-member-project-access');
    }
}
