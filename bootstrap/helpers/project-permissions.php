<?php

use App\Models\Team;
use App\Models\User;
use App\Models\Project;
use Illuminate\Support\Collection;

/**
 * Get project permissions configuration
 * Returns cached or loads from JSON file
 */
function getProjectPermissions(): array
{
    return cache()->remember('project-permissions', 60, function () {
        $path = storage_path('app/project-permissions.json');

        if (! file_exists($path)) {
            return [];
        }

        $content = file_get_contents($path);
        return json_decode($content, true) ?? [];
    });
}

/**
 * Clear project permissions cache
 * Call this after modifying permissions
 */
function clearProjectPermissionsCache(): void
{
    cache()->forget('project-permissions');
}

/**
 * Get allowed project IDs for a user in a team
 * Returns empty array if user has no restrictions (can access all)
 * Returns array of project IDs if user is restricted
 */
function getAllowedProjectIds(int $teamId, int $userId): array
{
    $permissions = getProjectPermissions();

    $key = "{$teamId}_{$userId}";

    return $permissions[$key] ?? [];
}

/**
 * Check if user has restricted project access for a team
 */
function hasRestrictedProjectAccess(int $teamId, int $userId): bool
{
    $allowedIds = getAllowedProjectIds($teamId, $userId);

    return count($allowedIds) > 0;
}

/**
 * Check if user can access a specific project
 */
function canUserAccessProject(int $teamId, int $userId, int $projectId): bool
{
    $allowedIds = getAllowedProjectIds($teamId, $userId);

    // Empty array = no restrictions
    if (empty($allowedIds)) {
        return true;
    }

    return in_array($projectId, $allowedIds);
}

/**
 * Set allowed projects for a user in a team
 * Replaces existing permissions
 */
function setAllowedProjects(int $teamId, int $userId, array $projectIds): void
{
    $path = storage_path('app/project-permissions.json');
    $lockPath = storage_path('app/project-permissions.lock');

    // Acquire lock
    $lock = fopen($lockPath, 'w');
    if (! flock($lock, LOCK_EX)) {
        throw new \Exception('Could not acquire lock to update permissions');
    }

    try {
        // Load existing permissions
        $permissions = [];
        if (file_exists($path)) {
            $content = file_get_contents($path);
            $permissions = json_decode($content, true) ?? [];
        }

        // Update permissions
        $key = "{$teamId}_{$userId}";

        if (empty($projectIds)) {
            // Remove entry if no projects (means no restrictions)
            unset($permissions[$key]);
        } else {
            $permissions[$key] = $projectIds;
        }

        // Save permissions
        file_put_contents($path, json_encode($permissions, JSON_PRETTY_PRINT));

        // Clear cache
        clearProjectPermissionsCache();
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

/**
 * Get all users with restricted access for a team
 * Returns array of ['userId' => [projectIds]]
 */
function getRestrictedUsers(int $teamId): array
{
    $permissions = getProjectPermissions();
    $restricted = [];

    $prefix = "{$teamId}_";

    foreach ($permissions as $key => $projectIds) {
        if (str_starts_with($key, $prefix)) {
            $userId = (int) str_replace($prefix, '', $key);
            $restricted[$userId] = $projectIds;
        }
    }

    return $restricted;
}

/**
 * Remove all project permissions for a user
 * Call when user is deleted or removed from team
 */
function removeUserProjectPermissions(int $userId): void
{
    $path = storage_path('app/project-permissions.json');
    $lockPath = storage_path('app/project-permissions.lock');

    if (! file_exists($path)) {
        return;
    }

    // Acquire lock
    $lock = fopen($lockPath, 'w');
    if (! flock($lock, LOCK_EX)) {
        throw new \Exception('Could not acquire lock to update permissions');
    }

    try {
        $permissions = json_decode(file_get_contents($path), true) ?? [];

        // Remove all entries for this user across all teams
        foreach (array_keys($permissions) as $key) {
            if (str_ends_with($key, "_{$userId}")) {
                unset($permissions[$key]);
            }
        }

        file_put_contents($path, json_encode($permissions, JSON_PRETTY_PRINT));

        clearProjectPermissionsCache();
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

/**
 * Remove all project permissions for a team
 * Call when team is deleted
 */
function removeTeamProjectPermissions(int $teamId): void
{
    $path = storage_path('app/project-permissions.json');
    $lockPath = storage_path('app/project-permissions.lock');

    if (! file_exists($path)) {
        return;
    }

    // Acquire lock
    $lock = fopen($lockPath, 'w');
    if (! flock($lock, LOCK_EX)) {
        throw new \Exception('Could not acquire lock to update permissions');
    }

    try {
        $permissions = json_decode(file_get_contents($path), true) ?? [];

        // Remove all entries for this team
        foreach (array_keys($permissions) as $key) {
            if (str_starts_with($key, "{$teamId}_")) {
                unset($permissions[$key]);
            }
        }

        file_put_contents($path, json_encode($permissions, JSON_PRETTY_PRINT));

        clearProjectPermissionsCache();
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

/**
 * Remove specific project from all permissions
 * Call when project is deleted
 */
function removeProjectFromPermissions(int $projectId): void
{
    $path = storage_path('app/project-permissions.json');
    $lockPath = storage_path('app/project-permissions.lock');

    if (! file_exists($path)) {
        return;
    }

    // Acquire lock
    $lock = fopen($lockPath, 'w');
    if (! flock($lock, LOCK_EX)) {
        throw new \Exception('Could not acquire lock to update permissions');
    }

    try {
        $permissions = json_decode(file_get_contents($path), true) ?? [];

        // Remove project ID from all permission entries
        foreach ($permissions as $key => $projectIds) {
            $permissions[$key] = array_values(array_diff($projectIds, [$projectId]));

            // Remove entry if no projects left
            if (empty($permissions[$key])) {
                unset($permissions[$key]);
            }
        }

        file_put_contents($path, json_encode($permissions, JSON_PRETTY_PRINT));

        clearProjectPermissionsCache();
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}
