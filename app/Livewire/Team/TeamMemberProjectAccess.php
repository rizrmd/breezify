<?php

namespace App\Livewire\Team;

use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Collection;
use Livewire\Component;

class TeamMemberProjectAccess extends Component
{
    public Team $team;

    public User $member;

    public Collection $allProjects;

    public array $selectedProjectIds = [];

    public bool $restrictAccess = false;

    public function mount(): void
    {
        $this->authorize('update', $this->team);
        $this->restrictAccess = $this->member->hasRestrictedProjectAccess($this->team->id);
        $this->selectedProjectIds = $this->restrictAccess
            ? $this->member->restrictedProjectIds($this->team->id)
            : [];
    }

    public function updatedRestrictAccess(bool $value): void
    {
        if (! $value) {
            $this->selectedProjectIds = [];
        }
    }

    public function save(): void
    {
        if ($this->member->isAdminOfTeam($this->team->id)) {
            $this->dispatch('error', 'Cannot restrict admin/owner access.');

            return;
        }
        if (! $this->restrictAccess) {
            setAllowedProjects($this->team->id, $this->member->id, null);
            $this->dispatch('success', 'Project access updated successfully.');

            return;
        }

        $validIds = $this->allProjects->pluck('id')->toArray();
        $this->selectedProjectIds = array_values(array_intersect($this->selectedProjectIds, $validIds));

        $validIds = $this->allProjects->pluck('id')->toArray();
        $this->selectedProjectIds = array_values(array_intersect($this->selectedProjectIds, $validIds));

        setAllowedProjects($this->team->id, $this->member->id, $this->selectedProjectIds);
        $this->dispatch('success', 'Project access updated successfully.');
    }

    public function render(): \Illuminate\View\View
    {
        return view('livewire.team.team-member-project-access');
    }
}
