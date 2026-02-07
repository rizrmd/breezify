<?php

namespace App\Policies;

use App\Models\Project;
use App\Models\User;

class ProjectPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return true;
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, Project $project): bool
    {
        return $project->isAccessibleBy($user);
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $user->isAdminFromSession();
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, Project $project): bool
    {
        return $project->isAccessibleBy($user) && $user->isAdminOfTeam($project->team_id);
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Project $project): bool
    {
        return $project->isAccessibleBy($user) && $user->isAdminOfTeam($project->team_id);
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, Project $project): bool
    {
        return $project->isAccessibleBy($user) && $user->isAdminOfTeam($project->team_id);
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, Project $project): bool
    {
        return $project->isAccessibleBy($user) && $user->isAdminOfTeam($project->team_id);
    }

    /**
     * Determine whether the user can create any resources in this project
     */
    public function createAnyResource(User $user, ?Project $project = null): bool
    {
        if ($project) {
            return $project->isAccessibleBy($user) && $user->isAdminOfTeam($project->team_id);
        }

        return false;
    }
}
