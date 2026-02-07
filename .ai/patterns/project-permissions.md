# Project-Level Permissions (Zero Database Changes)

## Overview

**⚠️ CONSTRAINT:** Zero database modifications for upstream compatibility.

This feature adds **project-level access control** using:
- Configuration-based permissions (JSON file)
- Helper functions in `app/Helpers/project-permissions.php`
- Extended authorization checks in Policies
- Manual merge strategy for upstream updates

**Use Cases:**
- **Client access**: Grant clients access to only their projects
- **Contractor access**: Limit contractors to specific projects
- **Team isolation**: Separate teams within one organization

## Architecture: Configuration-Based Access Control

### Storage: JSON Configuration File

**Location:** `storage/app/project-permissions.json`

**Structure:**
```json
{
    "team_1": {
        "user_5": [1, 3, 7],
        "user_12": [2, 4]
    },
    "team_2": {
        "user_8": [10, 11, 12]
    }
}
```

**Format:** `{team_id}_{user_id}` keys with array of project IDs as values.

**Benefits:**
- ✅ Zero database changes
- ✅ Easy to backup and version control
- ✅ Merge-friendly (JSON)
- ✅ Can be edited via UI or manually
- ✅ Survives upstream database migrations

### File Locking for Concurrent Access

Use `flock()` to prevent race conditions:
```php
$lockFile = fopen(storage_path('app/project-permissions.lock'), 'w');
if (flock($lockFile, LOCK_EX)) {
    $permissions = json_decode(file_get_contents(...), true);
    // Modify permissions
    file_put_contents(...);
    flock($lockFile, LOCK_UN);
}
fclose($lockFile);
```

## Implementation

### Step 1: Create Helper Functions

**File:** `bootstrap/helpers/project-permissions.php`

```php
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
```

### Step 2: Load Helper in Application

**File:** `bootstrap/app.php`

Add before the return statement:
```php
// Load custom helper functions
require_once __DIR__.'/helpers/project-permissions.php';
```

### Step 3: Add Methods to Models

**File:** `app/Models/Project.php`

```php
/**
 * Check if project is accessible by user
 * Respects configuration-based project permissions
 */
public function isAccessibleBy(User $user): bool
{
    // 1. User must be member of project's team
    if ($user->teams()->where('teams.id', $this->team_id)->doesntExist()) {
        return false;
    }

    // 2. Team admins/owners always have access
    if ($user->isAdminOfTeam($this->team_id)) {
        return true;
    }

    // 3. Check configuration-based permissions
    return canUserAccessProject($this->team_id, $user->id, $this->id);
}

/**
 * Get users with access to this project
 */
public function accessibleUsers(): Collection
{
    $team = $this->team;
    $allMembers = $team->members;

    return $allMembers->filter(function ($user) {
        return $this->isAccessibleBy($user);
    });
}
```

**File:** `app/Models/User.php`

```php
/**
 * Get accessible projects for a specific team
 * Respects configuration-based project permissions
 */
public function accessibleProjects(int $teamId): Collection
{
    // Admins/owners see all projects
    if ($this->isAdminOfTeam($teamId)) {
        return Project::where('team_id', $teamId)->get();
    }

    // Check if user has restrictions
    $allowedIds = getAllowedProjectIds($teamId, $this->id);

    if (empty($allowedIds)) {
        // No restrictions - return all team projects
        return Project::where('team_id', $teamId)->get();
    }

    // Restricted access - return only allowed projects
    return Project::where('team_id', $teamId)
        ->whereIn('id', $allowedIds)
        ->get();
}

/**
 * Check if user has restricted project access for a team
 */
public function hasRestrictedProjectAccess(int $teamId): bool
{
    if ($this->isAdminOfTeam($teamId)) {
        return false;
    }

    return hasRestrictedProjectAccess($teamId, $this->id);
}

/**
 * Get restricted project IDs for a team
 */
public function restrictedProjectIds(int $teamId): array
{
    return getAllowedProjectIds($teamId, $this->id);
}
```

### Step 4: Add Query Scope to Project Model

**File:** `app/Models/Project.php`

```php
/**
 * Scope: Get projects accessible by current user
 * Respects configuration-based project permissions
 */
public function scopeAccessibleBy(Builder $query, User $user): Builder
{
    $currentTeam = currentTeam();

    // Admins/owners see all projects
    if ($user->isAdminOfTeam($currentTeam->id)) {
        return $query->where('team_id', $currentTeam->id);
    }

    // Check if user has restricted access
    $allowedIds = getAllowedProjectIds($currentTeam->id, $user->id);

    if (empty($allowedIds)) {
        // No restrictions - return all team projects
        return $query->where('team_id', $currentTeam->id);
    }

    // Restricted access - return only allowed projects
    return $query->where('team_id', $currentTeam->id)
        ->whereIn('id', $allowedIds);
}
```

### Step 5: Update Policies

**File:** `app/Policies/ProjectPolicy.php`

```php
public function view(User $user, Project $project): bool
{
    return $project->isAccessibleBy($user);
}

public function update(User $user, Project $project): bool
{
    return $project->isAccessibleBy($user) && $user->isAdminOfTeam($project->team_id);
}

public function delete(User $user, Project $project): bool
{
    return $project->isAccessibleBy($user) && $user->isAdminOfTeam($project->team_id);
}

public function createAnyResource(User $user, ?Project $project = null): bool
{
    if ($project) {
        return $project->isAccessibleBy($user) && $user->isAdminOfTeam($project->team_id);
    }

    return false;
}
```

### Step 6: Livewire Component

**File:** `app/Livewire/Team/TeamMemberProjectAccess.php`

```php
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

        // Save to configuration file
        setAllowedProjects($this->team->id, $this->member->id, $this->selectedProjectIds);

        $this->dispatch('success', 'Project access updated successfully.');
    }

    public function render(): \Illuminate\View\View
    {
        return view('livewire.team.team-member-project-access');
    }
}
```

### Step 7: Frontend View

**File:** `resources/views/livewire/team/team-member-project-access.blade.php`

```html
<div>
    <!-- Header -->
    <div class="flex items-center justify-between mb-6">
        <h3 class="text-lg font-medium">Project Access for {{ $member->name }}</h3>
    </div>

    <!-- Access Status Indicator -->
    <div class="mb-6 p-4 rounded-lg border @if($member->hasRestrictedProjectAccess($team->id)) border-warning-200 bg-warning-50 @else border-success-200 bg-success-50 @endif">
        @if($member->hasRestrictedProjectAccess($team->id))
            <div class="flex items-start gap-3">
                <svg class="w-5 h-5 text-warning-600 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
                </svg>
                <div>
                    <p class="font-medium text-warning-800">Restricted Access</p>
                    <p class="text-sm text-warning-700 mt-1">
                        This user can only access {{ count($selectedProjectIds) }} of {{ $allProjects->count() }} team projects.
                    </p>
                </div>
            </div>
        @else
            <div class="flex items-start gap-3">
                <svg class="w-5 h-5 text-success-600 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                </svg>
                <div>
                    <p class="font-medium text-success-800">Full Access</p>
                    <p class="text-sm text-success-700 mt-1">
                        This user can access all {{ $allProjects->count() }} team projects.
                    </p>
                </div>
            </div>
        @endif
    </div>

    <!-- Project Selection Form -->
    <form wire:submit="save">
        <div class="space-y-3">
            @foreach($allProjects as $project)
                <label class="flex items-center p-4 border rounded-lg cursor-pointer hover:bg-gray-50 transition-colors @if(in_array($project->id, $selectedProjectIds)) bg-blue-50 border-blue-200 @endif">
                    <input
                        type="checkbox"
                        value="{{ $project->id }}"
                        wire:model.live="selectedProjectIds"
                        @if($member->isAdminOfTeam($team->id))
                            disabled
                        @endif
                        class="w-4 h-4 text-blue-600 rounded focus:ring-blue-500"
                    />

                    <div class="ml-4 flex-1">
                        <div class="font-medium text-gray-900">{{ $project->name }}</div>
                        @if($project->description)
                            <div class="text-sm text-gray-500 mt-0.5">{{ $project->description }}</div>
                        @endif
                        <div class="text-xs text-gray-400 mt-1">
                            {{ $project->environments->count() }} environment(s)
                        </div>
                    </div>

                    @if(in_array($project->id, $selectedProjectIds))
                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-blue-100 text-blue-800">
                            Access Granted
                        </span>
                    @endif
                </label>
            @endforeach

            @if($allProjects->isEmpty())
                <div class="text-center py-12 text-gray-500">
                    <svg class="mx-auto h-12 w-12 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"/>
                    </svg>
                    <p class="mt-2">No projects found in this team.</p>
                </div>
            @endif
        </div>

        @if(!$member->isAdminOfTeam($team->id) && $allProjects->isNotEmpty())
            <div class="mt-6 flex items-center justify-between">
                <div class="text-sm text-gray-500">
                    <span class="font-medium">{{ count($selectedProjectIds) }}</span> of {{ $allProjects->count() }} projects selected
                </div>
                <x-forms.button type="submit">
                    Save Project Access
                </x-forms.button>
            </div>
        @endif
    </form>

    <!-- Admin Notice -->
    @if($member->isAdminOfTeam($team->id))
        <div class="mt-6 p-4 bg-blue-50 border border-blue-200 rounded-lg">
            <div class="flex">
                <svg class="w-5 h-5 text-blue-400 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                </svg>
                <div class="ml-3">
                    <p class="text-sm text-blue-800">
                        <strong>Team admins and owners</strong> automatically have access to all projects and cannot be restricted.
                    </p>
                </div>
            </div>
        </div>
    @endif
</div>
```

### Step 8: Add Route

**File:** `routes/web.php`

```php
Route::get('/teams/{team}/members/{member}/project-access', \App\Livewire\Team\TeamMemberProjectAccess::class)
    ->name('team.member.project-access');
```

### Step 9: Cleanup on Resource Deletion

**File:** `app/Models/Team.php` - add to `deleting` event:

```php
protected static function booted()
{
    static::deleting(function ($team) {
        // ... existing cleanup code ...

        // Remove project permissions for this team
        removeTeamProjectPermissions($team->id);
    });
}
```

**File:** `app/Models/User.php` - add to `deleting` event:

```php
protected static function boot()
{
    parent::boot();

    static::deleting(function (User $user) {
        // ... existing cleanup code ...

        // Remove project permissions for this user
        removeUserProjectPermissions($user->id);
    });
}
```

**File:** `app/Models/Project.php` - add to `deleting` event:

```php
protected static function booted()
{
    static::deleting(function ($project) {
        // ... existing cleanup code ...

        // Remove this project from all permissions
        removeProjectFromPermissions($project->id);
    });
}
```

## Testing

### Unit Tests (`tests/Unit/`)

```php
// tests/Unit/ProjectPermissionsTest.php

beforeEach(function () {
    // Create temporary permissions file
    $this->permissionsPath = storage_path('app/project-permissions.json');
    $this->backupPath = storage_path('app/project-permissions.json.backup');

    if (file_exists($this->permissionsPath)) {
        copy($this->permissionsPath, $this->backupPath);
    }
});

afterEach(function () {
    // Restore original permissions
    if (file_exists($this->backupPath)) {
        copy($this->backupPath, $this->permissionsPath);
        unlink($this->backupPath);
    } elseif (file_exists($this->permissionsPath)) {
        unlink($this->permissionsPath);
    }

    clearProjectPermissionsCache();
});

test('getAllowedProjectIds returns empty array for unrestricted user', function () {
    $result = getAllowedProjectIds(teamId: 1, userId: 5);

    expect($result)->toBe([]);
});

test('getAllowedProjectIds returns project ids for restricted user', function () {
    setAllowedProjects(1, 5, [1, 3, 7]);

    $result = getAllowedProjectIds(1, 5);

    expect($result)->toBe([1, 3, 7]);
});

test('hasRestrictedProjectAccess returns false for unrestricted user', function () {
    $result = hasRestrictedProjectAccess(1, 5);

    expect($result)->toBeFalse();
});

test('hasRestrictedProjectAccess returns true for restricted user', function () {
    setAllowedProjects(1, 5, [1, 3]);

    $result = hasRestrictedProjectAccess(1, 5);

    expect($result)->toBeTrue();
});

test('canUserAccessProject returns true when unrestricted', function () {
    $result = canUserAccessProject(1, 5, 99);

    expect($result)->toBeTrue();
});

test('canUserAccessProject returns true for allowed project', function () {
    setAllowedProjects(1, 5, [1, 3, 7]);

    expect(canUserAccessProject(1, 5, 1))->toBeTrue();
    expect(canUserAccessProject(1, 5, 3))->toBeTrue();
});

test('canUserAccessProject returns false for restricted project', function () {
    setAllowedProjects(1, 5, [1, 3, 7]);

    expect(canUserAccessProject(1, 5, 2))->toBeFalse();
    expect(canUserAccessProject(1, 5, 99))->toBeFalse();
});

test('setAllowedProjects with empty array removes restrictions', function () {
    setAllowedProjects(1, 5, [1, 3, 7]);
    expect(hasRestrictedProjectAccess(1, 5))->toBeTrue();

    setAllowedProjects(1, 5, []);
    expect(hasRestrictedProjectAccess(1, 5))->toBeFalse();
});

test('removeUserProjectPermissions removes user from all teams', function () {
    setAllowedProjects(1, 5, [1, 2]);
    setAllowedProjects(2, 5, [3, 4]);

    removeUserProjectPermissions(5);

    expect(getAllowedProjectIds(1, 5))->toBe([]);
    expect(getAllowedProjectIds(2, 5))->toBe([]);
});

test('removeTeamProjectPermissions removes all users in team', function () {
    setAllowedProjects(1, 5, [1, 2]);
    setAllowedProjects(1, 8, [3, 4]);

    removeTeamProjectPermissions(1);

    expect(getAllowedProjectIds(1, 5))->toBe([]);
    expect(getAllowedProjectIds(1, 8))->toBe([]);
});

test('removeProjectFromPermissions removes project from all users', function () {
    setAllowedProjects(1, 5, [1, 2, 3]);
    setAllowedProjects(1, 8, [2, 4]);

    removeProjectFromPermissions(2);

    expect(getAllowedProjectIds(1, 5))->toBe([1, 3]);
    expect(getAllowedProjectIds(1, 8))->toBe([4]);
});
```

### Feature Tests (`tests/Feature/`)

```php
// tests/Feature/ProjectAccessTest.php

test('admin can access all projects', function () {
    $team = Team::factory()->create();
    $admin = User::factory()->create();
    $team->members()->attach($admin, ['role' => 'admin']);

    $project1 = Project::factory()->create(['team_id' => $team->id]);
    $project2 = Project::factory()->create(['team_id' => $team->id]);

    actingAs($admin)
        ->get("/projects/{$project1->uuid}")
        ->assertStatus(200);

    actingAs($admin)
        ->get("/projects/{$project2->uuid}")
        ->assertStatus(200);
});

test('member with no restrictions can access all projects', function () {
    $team = Team::factory()->create();
    $member = User::factory()->create();
    $team->members()->attach($member, ['role' => 'member']);

    $project1 = Project::factory()->create(['team_id' => $team->id]);
    $project2 = Project::factory()->create(['team_id' => $team->id]);

    actingAs($member)
        ->get("/projects/{$project1->uuid}")
        ->assertStatus(200);

    actingAs($member)
        ->get("/projects/{$project2->uuid}")
        ->assertStatus(200);
});

test('restricted member can only access assigned projects', function () {
    $team = Team::factory()->create();
    $member = User::factory()->create();
    $team->members()->attach($member, ['role' => 'member']);

    $project1 = Project::factory()->create(['team_id' => $team->id]);
    $project2 = Project::factory()->create(['team_id' => $team->id]);

    setAllowedProjects($team->id, $member->id, [$project1->id]);

    actingAs($member)
        ->get("/projects/{$project1->uuid}")
        ->assertStatus(200);

    actingAs($member)
        ->get("/projects/{$project2->uuid}")
        ->assertStatus(403);
});
```

## Upstream Compatibility Strategy

### Manual Merge Process

**When updating from upstream:**

1. **Backup custom files:**
   ```bash
   cp bootstrap/helpers/project-permissions.php ~/backup/
   cp storage/app/project-permissions.json ~/backup/
   ```

2. **Pull upstream changes:**
   ```bash
   git pull upstream main
   ```

3. **Restore custom files:**
   ```bash
   cp ~/backup/project-permissions.php bootstrap/helpers/
   cp ~/backup/project-permissions.json storage/app/
   ```

4. **Re-apply any conflicts:**
   - Check `app/Models/Project.php` for `isAccessibleBy()` method
   - Check `app/Models/User.php` for `accessibleProjects()` method
   - Check `app/Policies/ProjectPolicy.php` for updated methods

5. **Test:**
   ```bash
   ./vendor/bin/pest tests/Unit/ProjectPermissionsTest.php
   docker exec coolify php artisan test --filter ProjectAccessTest
   ```

### Files That May Conflict

These files may need manual merging after upstream updates:

- `app/Models/Project.php` - Check for `isAccessibleBy()` and `scopeAccessibleBy()`
- `app/Models/User.php` - Check for `accessibleProjects()` and related methods
- `app/Policies/ProjectPolicy.php` - Check for updated `view()`, `update()`, `delete()` methods
- `bootstrap/app.php` - Check for helper file inclusion
- `routes/web.php` - Check for new route

### Files That Won't Conflict

These are new files that won't conflict with upstream:

- `bootstrap/helpers/project-permissions.php`
- `app/Livewire/Team/TeamMemberProjectAccess.php`
- `resources/views/livewire/team/team-member-project-access.blade.php`
- `storage/app/project-permissions.json`
- `tests/Unit/ProjectPermissionsTest.php`
- `tests/Feature/ProjectAccessTest.php`

### Git Merge Strategy

**Use strategy-option to preserve custom files:**

```bash
# Configure git to preserve our custom files
git config merge.ours.driver true

# Mark files as always use ours
echo "bootstrap/helpers/project-permissions.php merge=ours" >> .gitattributes
echo "storage/app/project-permissions.json merge=ours" >> .gitattributes
echo "app/Livewire/Team/TeamMemberProjectAccess.php merge=ours" >> .gitattributes
```

## Performance Considerations

### Caching

- **File-based caching** with 60-second TTL
- **Automatic invalidation** on permissions change
- **Request-level cache** using Laravel's cache system

### Query Optimization

**Allowed projects are filtered in single query:**
```php
Project::where('team_id', $teamId)
    ->whereIn('id', $allowedIds)
    ->get();
```

**No N+1 queries** with eager loading:
```php
Project::accessibleBy($user)
    ->with(['environments', 'applications'])
    ->get();
```

### File I/O Optimization

- **File locking** prevents concurrent write issues
- **Atomic writes** with flock prevent corruption
- **JSON format** is fast to parse/serialize

## Security Considerations

### File Permissions

**Ensure proper file permissions:**
```bash
chmod 640 storage/app/project-permissions.json
chown www-data:www-data storage/app/project-permissions.json
```

### Input Validation

**Validate project IDs before saving:**
```php
public function save(): void
{
    // Validate all selected projects belong to the team
    $validIds = Project::where('team_id', $this->team->id)
        ->pluck('id')
        ->toArray();

    $this->selectedProjectIds = array_intersect(
        $this->selectedProjectIds,
        $validIds
    );

    setAllowedProjects($this->team->id, $this->member->id, $this->selectedProjectIds);
}
```

### Authorization

**Every access must verify:**
1. User is member of the team
2. User has permission to access the project
3. Admins cannot be restricted

## Implementation Checklist

### Phase 1: Helper Functions
- [ ] Create `bootstrap/helpers/project-permissions.php`
- [ ] Add helper file to `bootstrap/app.php`
- [ ] Create `storage/app/project-permissions.json`
- [ ] Test helper functions with Tinker

### Phase 2: Model Methods
- [ ] Add methods to `Project.php`
- [ ] Add methods to `User.php`
- [ ] Add cleanup to model deletion events

### Phase 3: Authorization
- [ ] Update `ProjectPolicy`
- [ ] Test access control with different user roles

### Phase 4: Livewire Component
- [ ] Create `TeamMemberProjectAccess` component
- [ ] Create view for project access management
- [ ] Add route

### Phase 5: Frontend Integration
- [ ] Update team members list (access indicators)
- [ ] Add "Manage Access" buttons
- [ ] Test UI interactions

### Phase 6: Testing
- [ ] Write unit tests for helper functions
- [ ] Write feature tests for access control
- [ ] Run test suite
- [ ] Manual testing

### Phase 7: Documentation
- [ ] Update this document with any changes
- [ ] Create user guide
- [ ] Document merge process for upstream updates

## Summary

This implementation provides **project-level access control** with:
- ✅ **Zero database changes** (full upstream compatibility)
- ✅ **Configuration-based** (easy to backup and version control)
- ✅ **Performant** (cached, single-query filtering)
- ✅ **Secure** (file locking, validation, authorization)
- ✅ **Tested** (unit + feature tests)
- ✅ **Documented** (clear merge strategy)

The trade-off is **manual merge effort** when updating from upstream, but this is manageable with proper documentation and git configuration.
