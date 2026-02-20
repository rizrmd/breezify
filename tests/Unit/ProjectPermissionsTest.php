<?php

uses(Tests\TestCase::class);

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

test('setAllowedProjects with empty array keeps restriction but removes access', function () {
    setAllowedProjects(1, 5, [1, 3, 7]);
    expect(hasRestrictedProjectAccess(1, 5))->toBeTrue();
    setAllowedProjects(1, 5, []);
    expect(hasRestrictedProjectAccess(1, 5))->toBeTrue();
    expect(getAllowedProjectIds(1, 5))->toBe([]);
});

test('setAllowedProjects with null removes restrictions', function () {
    setAllowedProjects(1, 5, [1, 2]);
    expect(hasRestrictedProjectAccess(1, 5))->toBeTrue();

    setAllowedProjects(1, 5, null);
    expect(hasRestrictedProjectAccess(1, 5))->toBeFalse();
});

test('setAllowedProjects overwrites existing permissions', function () {
    setAllowedProjects(1, 5, [1, 2, 3]);
    expect(getAllowedProjectIds(1, 5))->toBe([1, 2, 3]);

    setAllowedProjects(1, 5, [4, 5]);
    expect(getAllowedProjectIds(1, 5))->toBe([4, 5]);
});

test('getRestrictedUsers returns all restricted users for a team', function () {
    setAllowedProjects(1, 5, [1, 2]);
    setAllowedProjects(1, 8, [3, 4]);
    setAllowedProjects(2, 5, [5, 6]);

    $team1Restricted = getRestrictedUsers(1);

    expect($team1Restricted)->toBe([
        5 => [1, 2],
        8 => [3, 4],
    ]);
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
    setAllowedProjects(2, 5, [5, 6]);

    removeTeamProjectPermissions(1);

    expect(getAllowedProjectIds(1, 5))->toBe([]);
    expect(getAllowedProjectIds(1, 8))->toBe([]);
    expect(getAllowedProjectIds(2, 5))->toBe([5, 6]); // Should still exist
});

test('removeProjectFromPermissions removes project from all users', function () {
    setAllowedProjects(1, 5, [1, 2, 3]);
    setAllowedProjects(1, 8, [2, 4]);

    removeProjectFromPermissions(2);

    expect(getAllowedProjectIds(1, 5))->toBe([1, 3]);
    expect(getAllowedProjectIds(1, 8))->toBe([4]);
});

test('removeProjectFromPermissions keeps restriction when no projects left', function () {
    setAllowedProjects(1, 5, [2]);
    removeProjectFromPermissions(2);

    expect(hasRestrictedProjectAccess(1, 5))->toBeTrue();
    expect(getAllowedProjectIds(1, 5))->toBe([]);
});

test('permissions are cached and cleared properly', function () {
    setAllowedProjects(1, 5, [1, 2]);

    // First call loads from file
    $result1 = getAllowedProjectIds(1, 5);
    expect($result1)->toBe([1, 2]);

    // Modify file directly
    $path = storage_path('app/project-permissions.json');
    $permissions = json_decode(file_get_contents($path), true);
    $permissions['1_5'] = [3, 4];
    file_put_contents($path, json_encode($permissions, JSON_PRETTY_PRINT));

    // Second call returns cached value (still [1, 2])
    $result2 = getAllowedProjectIds(1, 5);
    expect($result2)->toBe([1, 2]);

    // Clear cache
    clearProjectPermissionsCache();

    // Third call loads new value from file
    $result3 = getAllowedProjectIds(1, 5);
    expect($result3)->toBe([3, 4]);
});

test('permissions file is created if it does not exist', function () {
    // Remove file if it exists
    $path = storage_path('app/project-permissions.json');
    if (file_exists($path)) {
        unlink($path);
    }

    expect(file_exists($path))->toBeFalse();

    // Setting permissions should create the file
    setAllowedProjects(1, 5, [1, 2]);

    expect(file_exists($path))->toBeTrue();
    expect(json_decode(file_get_contents($path), true))->toBe(['1_5' => [1, 2]]);
});

test('concurrent writes are handled with file locking', function () {
    // This test verifies that the file locking mechanism doesn't throw errors
    // In a real scenario with actual concurrency, this would prevent corruption
    setAllowedProjects(1, 5, [1, 2]);
    setAllowedProjects(1, 6, [3, 4]);
    setAllowedProjects(2, 7, [5, 6]);

    expect(getAllowedProjectIds(1, 5))->toBe([1, 2]);
    expect(getAllowedProjectIds(1, 6))->toBe([3, 4]);
    expect(getAllowedProjectIds(2, 7))->toBe([5, 6]);
});

test('getProjectPermissions returns array from valid JSON', function () {
    setAllowedProjects(1, 5, [1, 2]);

    $permissions = getProjectPermissions();

    expect($permissions)->toBeArray();
    expect($permissions)->toHaveKey('1_5');
});
