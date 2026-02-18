<?php

use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use App\Policies\ServerPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('includes shared servers in team access when enabled', function () {
    config(['constants.coolify.shared_servers_enabled' => true]);

    $ownerTeam = Team::factory()->create();
    $guestTeam = Team::factory()->create();
    $server = Server::factory()->create(['team_id' => $ownerTeam->id]);

    $server->sharedTeams()->attach($guestTeam->id);

    $accessibleServerIds = Server::query()->accessibleByTeam($guestTeam->id)->pluck('id');

    expect($accessibleServerIds)->toContain($server->id);
    expect($guestTeam->accessibleServerCount())->toBe(1);
});

it('excludes shared servers when feature flag is disabled', function () {
    config(['constants.coolify.shared_servers_enabled' => false]);

    $ownerTeam = Team::factory()->create();
    $guestTeam = Team::factory()->create();
    $server = Server::factory()->create(['team_id' => $ownerTeam->id]);

    $server->sharedTeams()->attach($guestTeam->id);

    $accessibleServerIds = Server::query()->accessibleByTeam($guestTeam->id)->pluck('id');

    expect($accessibleServerIds)->not()->toContain($server->id);
    expect($guestTeam->accessibleServerCount())->toBe(0);
});

it('allows shared server view access via policy when enabled', function () {
    config(['constants.coolify.shared_servers_enabled' => true]);

    $ownerTeam = Team::factory()->create();
    $guestTeam = Team::factory()->create();
    $server = Server::factory()->create(['team_id' => $ownerTeam->id]);
    $user = User::factory()->create();

    $guestTeam->members()->attach($user->id, ['role' => 'member']);
    $server->sharedTeams()->attach($guestTeam->id);

    $user->load('teams');

    $policy = new ServerPolicy();

    expect($policy->view($user, $server))->toBeTrue();
});
