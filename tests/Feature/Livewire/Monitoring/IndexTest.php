<?php

use App\Livewire\Monitoring\Index;
use Livewire\Livewire;

it('renders successfully', function () {
    $user = \App\Models\User::factory()->create();
    $team = \App\Models\Team::factory()->create();
    $user->teams()->attach($team->id, ['role' => 'owner']);

    $this->actingAs($user);
    session(['currentTeam' => $team]);

    Livewire::test(Index::class)
        ->assertStatus(200);
});

it('shows all teams resources for root team', function () {
    $user = \App\Models\User::factory()->create();
    $rootTeam = \App\Models\Team::factory()->create(['id' => 0, 'name' => 'Root Team']);
    $user->teams()->attach($rootTeam->id, ['role' => 'owner']);

    // Create another team and a server for it.
    $otherTeam = \App\Models\Team::factory()->create();
    \App\Models\Server::factory()->create(['team_id' => $otherTeam->id]);

    $this->actingAs($user);
    session(['currentTeam' => $rootTeam]);

    Livewire::test(Index::class)
        ->assertStatus(200)
        ->assertSee('Viewing resources across all teams.');
});
