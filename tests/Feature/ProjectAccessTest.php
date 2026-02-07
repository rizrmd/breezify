<?php

use App\Models\Project;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Clean up permissions file before each test
    $path = storage_path('app/project-permissions.json');
    if (file_exists($path)) {
        unlink($path);
    }
    clearProjectPermissionsCache();
});

afterEach(function () {
    // Clean up permissions file after each test
    $path = storage_path('app/project-permissions.json');
    if (file_exists($path)) {
        unlink($path);
    }
    clearProjectPermissionsCache();
});

test('admin can access all projects in their team', function () {
    $team = Team::factory()->create();
    $admin = User::factory()->create();
    $team->members()->attach($admin, ['role' => 'admin']);

    $project1 = Project::factory()->create(['team_id' => $team->id]);
    $project2 = Project::factory()->create(['team_id' => $team->id]);

    expect($project1->isAccessibleBy($admin))->toBeTrue();
    expect($project2->isAccessibleBy($admin))->toBeTrue();
});

test('owner can access all projects in their team', function () {
    $team = Team::factory()->create();
    $owner = User::factory()->create();
    $team->members()->attach($owner, ['role' => 'owner']);

    $project1 = Project::factory()->create(['team_id' => $team->id]);
    $project2 = Project::factory()->create(['team_id' => $team->id]);

    expect($project1->isAccessibleBy($owner))->toBeTrue();
    expect($project2->isAccessibleBy($owner))->toBeTrue();
});

test('member with no restrictions can access all team projects', function () {
    $team = Team::factory()->create();
    $member = User::factory()->create();
    $team->members()->attach($member, ['role' => 'member']);

    $project1 = Project::factory()->create(['team_id' => $team->id]);
    $project2 = Project::factory()->create(['team_id' => $team->id]);

    expect($project1->isAccessibleBy($member))->toBeTrue();
    expect($project2->isAccessibleBy($member))->toBeTrue();
});

test('restricted member can only access assigned projects', function () {
    $team = Team::factory()->create();
    $member = User::factory()->create();
    $team->members()->attach($member, ['role' => 'member']);

    $project1 = Project::factory()->create(['team_id' => $team->id]);
    $project2 = Project::factory()->create(['team_id' => $team->id]);
    $project3 = Project::factory()->create(['team_id' => $team->id]);

    // Grant access to project1 only
    setAllowedProjects($team->id, $member->id, [$project1->id]);

    expect($project1->isAccessibleBy($member))->toBeTrue();
    expect($project2->isAccessibleBy($member))->toBeFalse();
    expect($project3->isAccessibleBy($member))->toBeFalse();
});

test('user cannot access projects from other teams', function () {
    $team1 = Team::factory()->create();
    $team2 = Team::factory()->create();

    $user = User::factory()->create();
    $user->teams()->attach($team1, ['role' => 'member']);

    $project = Project::factory()->create(['team_id' => $team2->id]);

    expect($project->isAccessibleBy($user))->toBeFalse();
});

test('accessibleProjects returns all projects for unrestricted member', function () {
    $team = Team::factory()->create();
    $member = User::factory()->create();
    $team->members()->attach($member, ['role' => 'member']);

    $project1 = Project::factory()->create(['team_id' => $team->id]);
    $project2 = Project::factory()->create(['team_id' => $team->id]);

    $accessibleProjects = $member->accessibleProjects($team->id);

    expect($accessibleProjects)->toHaveCount(2);
    expect($accessibleProjects->pluck('id')->toArray())->toContain($project1->id, $project2->id);
});

test('accessibleProjects returns only assigned projects for restricted member', function () {
    $team = Team::factory()->create();
    $member = User::factory()->create();
    $team->members()->attach($member, ['role' => 'member']);

    $project1 = Project::factory()->create(['team_id' => $team->id]);
    $project2 = Project::factory()->create(['team_id' => $team->id]);
    $project3 = Project::factory()->create(['team_id' => $team->id]);

    setAllowedProjects($team->id, $member->id, [$project1->id, $project3->id]);

    $accessibleProjects = $member->accessibleProjects($team->id);

    expect($accessibleProjects)->toHaveCount(2);
    expect($accessibleProjects->pluck('id')->toArray())->toContain($project1->id, $project3->id);
    expect($accessibleProjects->pluck('id')->toArray())->not->toContain($project2->id);
});

test('hasRestrictedProjectAccess returns correct status', function () {
    $team = Team::factory()->create();
    $member = User::factory()->create();
    $team->members()->attach($member, ['role' => 'member']);

    expect($member->hasRestrictedProjectAccess($team->id))->toBeFalse();

    setAllowedProjects($team->id, $member->id, [1, 2, 3]);

    expect($member->hasRestrictedProjectAccess($team->id))->toBeTrue();
});

test('restrictedProjectIds returns correct project IDs', function () {
    $team = Team::factory()->create();
    $member = User::factory()->create();
    $team->members()->attach($member, ['role' => 'member']);

    $project1 = Project::factory()->create(['team_id' => $team->id]);
    $project2 = Project::factory()->create(['team_id' => $team->id]);

    setAllowedProjects($team->id, $member->id, [$project1->id, $project2->id]);

    expect($member->restrictedProjectIds($team->id))->toBe([$project1->id, $project2->id]);
});

test('isAdminOfTeam returns true for admin and owner', function () {
    $team = Team::factory()->create();
    $admin = User::factory()->create();
    $owner = User::factory()->create();
    $member = User::factory()->create();

    $team->members()->attach($admin, ['role' => 'admin']);
    $team->members()->attach($owner, ['role' => 'owner']);
    $team->members()->attach($member, ['role' => 'member']);

    expect($admin->isAdminOfTeam($team->id))->toBeTrue();
    expect($owner->isAdminOfTeam($team->id))->toBeTrue();
    expect($member->isAdminOfTeam($team->id))->toBeFalse();
});

test('scopeAccessibleBy returns all projects for admin', function () {
    $team = Team::factory()->create();
    $admin = User::factory()->create();
    $team->members()->attach($admin, ['role' => 'admin']);

    Project::factory()->create(['team_id' => $team->id]);
    Project::factory()->create(['team_id' => $team->id]);
    Project::factory()->create(['team_id' => $team->id]);

    $projects = Project::accessibleBy($admin)->get();

    expect($projects)->toHaveCount(3);
});

test('scopeAccessibleBy returns only assigned projects for restricted member', function () {
    $team = Team::factory()->create();
    $member = User::factory()->create();
    $team->members()->attach($member, ['role' => 'member']);

    $project1 = Project::factory()->create(['team_id' => $team->id]);
    $project2 = Project::factory()->create(['team_id' => $team->id]);
    $project3 = Project::factory()->create(['team_id' => $team->id]);

    setAllowedProjects($team->id, $member->id, [$project1->id, $project3->id]);

    $projects = Project::accessibleBy($member)->get();

    expect($projects)->toHaveCount(2);
    expect($projects->pluck('id')->toArray())->toContain($project1->id, $project3->id);
    expect($projects->pluck('id')->toArray())->not->toContain($project2->id);
});

test('accessibleUsers returns all team members for unrestricted project', function () {
    $team = Team::factory()->create();
    $admin = User::factory()->create();
    $member1 = User::factory()->create();
    $member2 = User::factory()->create();

    $team->members()->attach($admin, ['role' => 'admin']);
    $team->members()->attach($member1, ['role' => 'member']);
    $team->members()->attach($member2, ['role' => 'member']);

    $project = Project::factory()->create(['team_id' => $team->id]);

    $accessibleUsers = $project->accessibleUsers();

    expect($accessibleUsers)->toHaveCount(3);
});

test('accessibleUsers returns only authorized users for restricted project', function () {
    $team = Team::factory()->create();
    $admin = User::factory()->create();
    $member1 = User::factory()->create();
    $member2 = User::factory()->create();

    $team->members()->attach($admin, ['role' => 'admin']);
    $team->members()->attach($member1, ['role' => 'member']);
    $team->members()->attach($member2, ['role' => 'member']);

    $project = Project::factory()->create(['team_id' => $team->id]);

    // Restrict member1 to not have access to this project
    setAllowedProjects($team->id, $member1->id, []);

    $accessibleUsers = $project->accessibleUsers();

    expect($accessibleUsers)->toHaveCount(2);
    expect($accessibleUsers->pluck('id')->toArray())->toContain($admin->id, $member2->id);
    expect($accessibleUsers->pluck('id')->toArray())->not->toContain($member1->id);
});

test('ProjectPolicy view respects project access', function () {
    $team = Team::factory()->create();
    $member = User::factory()->create();
    $team->members()->attach($member, ['role' => 'member']);

    $project = Project::factory()->create(['team_id' => $team->id]);

    $policy = new \App\Policies\ProjectPolicy();

    // Unrestricted member can view
    expect($policy->view($member, $project))->toBeTrue();

    // Restricted member cannot view
    setAllowedProjects($team->id, $member->id, []);
    expect($policy->view($member, $project))->toBeFalse();
});

test('ProjectPolicy update requires admin privileges', function () {
    $team = Team::factory()->create();
    $admin = User::factory()->create();
    $member = User::factory()->create();

    $team->members()->attach($admin, ['role' => 'admin']);
    $team->members()->attach($member, ['role' => 'member']);

    $project = Project::factory()->create(['team_id' => $team->id]);

    $policy = new \App\Policies\ProjectPolicy();

    expect($policy->update($admin, $project))->toBeTrue();
    expect($policy->update($member, $project))->toBeFalse();
});

test('ProjectPolicy delete requires admin privileges', function () {
    $team = Team::factory()->create();
    $owner = User::factory()->create();
    $member = User::factory()->create();

    $team->members()->attach($owner, ['role' => 'owner']);
    $team->members()->attach($member, ['role' => 'member']);

    $project = Project::factory()->create(['team_id' => $team->id]);

    $policy = new \App\Policies\ProjectPolicy();

    expect($policy->delete($owner, $project))->toBeTrue();
    expect($policy->delete($member, $project))->toBeFalse();
});

test('ProjectPolicy createAnyResource requires admin and project access', function () {
    $team = Team::factory()->create();
    $admin = User::factory()->create();
    $member = User::factory()->create();

    $team->members()->attach($admin, ['role' => 'admin']);
    $team->members()->attach($member, ['role' => 'member']);

    $project = Project::factory()->create(['team_id' => $team->id]);

    $policy = new \App\Policies\ProjectPolicy();

    expect($policy->createAnyResource($admin, $project))->toBeTrue();
    expect($policy->createAnyResource($member, $project))->toBeFalse();
    expect($policy->createAnyResource($admin, null))->toBeFalse();
});

test('deleting team removes all team permissions', function () {
    $team = Team::factory()->create();
    $member = User::factory()->create();
    $team->members()->attach($member, ['role' => 'member']);

    setAllowedProjects($team->id, $member->id, [1, 2, 3]);

    expect(hasRestrictedProjectAccess($team->id, $member->id))->toBeTrue();

    $team->delete();

    expect(hasRestrictedProjectAccess($team->id, $member->id))->toBeFalse();
});

test('deleting user removes all user permissions', function () {
    $team1 = Team::factory()->create();
    $team2 = Team::factory()->create();
    $user = User::factory()->create();

    $team1->members()->attach($user, ['role' => 'member']);
    $team2->members()->attach($user, ['role' => 'member']);

    setAllowedProjects($team1->id, $user->id, [1, 2]);
    setAllowedProjects($team2->id, $user->id, [3, 4]);

    expect(getAllowedProjectIds($team1->id, $user->id))->toBe([1, 2]);
    expect(getAllowedProjectIds($team2->id, $user->id))->toBe([3, 4]);

    $user->delete();

    expect(getAllowedProjectIds($team1->id, $user->id))->toBe([]);
    expect(getAllowedProjectIds($team2->id, $user->id))->toBe([]);
});

test('deleting project removes it from all permissions', function () {
    $team = Team::factory()->create();
    $member1 = User::factory()->create();
    $member2 = User::factory()->create();

    $team->members()->attach($member1, ['role' => 'member']);
    $team->members()->attach($member2, ['role' => 'member']);

    $project1 = Project::factory()->create(['team_id' => $team->id]);
    $project2 = Project::factory()->create(['team_id' => $team->id]);

    setAllowedProjects($team->id, $member1->id, [$project1->id, $project2->id]);
    setAllowedProjects($team->id, $member2->id, [$project2->id]);

    expect(getAllowedProjectIds($team->id, $member1->id))->toBe([$project1->id, $project2->id]);
    expect(getAllowedProjectIds($team->id, $member2->id))->toBe([$project2->id]);

    $project2->delete();

    expect(getAllowedProjectIds($team->id, $member1->id))->toBe([$project1->id]);
    expect(getAllowedProjectIds($team->id, $member2->id))->toBe([]);
});
