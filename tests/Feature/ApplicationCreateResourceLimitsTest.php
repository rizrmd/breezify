<?php

use App\Models\Application;
use App\Models\GithubApp;
use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Visus\Cuid2\Cuid2;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);

    $privateKeyValue = <<<'KEY'
-----BEGIN OPENSSH PRIVATE KEY-----
b3BlbnNzaC1rZXktdjEAAAAABG5vbmUAAAAEbm9uZQAAAAAAAAABAAAAMwAAAAtzc2gtZW
QyNTUxOQAAACBbhpqHhqv6aI67Mj9abM3DVbmcfYhZAhC7ca4d9UCevAAAAJi/QySHv0Mk
hwAAAAtzc2gtZWQyNTUxOQAAACBbhpqHhqv6aI67Mj9abM3DVbmcfYhZAhC7ca4d9UCevA
AAAECBQw4jg1WRT2IGHMncCiZhURCts2s24HoDS0thHnnRKVuGmoeGq/pojrsyP1pszcNV
uZx9iFkCELtxrh31QJ68AAAAEXNhaWxANzZmZjY2ZDJlMmRkAQIDBA==
-----END OPENSSH PRIVATE KEY-----
KEY;

    session(['currentTeam' => $this->team]);

    config(['logging.default' => 'null']);

    config([
        'cache.default' => 'array',
        'cache.stores.redis.driver' => 'array',
    ]);

    $this->token = $this->user->createToken('test-token', ['write']);
    $this->bearerToken = $this->token->plainTextToken;

    InstanceSettings::updateOrCreate([
        'id' => 0,
    ], [
        'is_api_enabled' => true,
    ]);

    GithubApp::create([
        'id' => 0,
        'uuid' => (string) new Cuid2,
        'name' => 'System Github App',
        'api_url' => 'https://api.github.com',
        'html_url' => 'https://github.com',
        'team_id' => $this->team->id,
    ]);

    $privateKey = PrivateKey::create([
        'name' => 'Test Key',
        'private_key' => $privateKeyValue,
        'team_id' => $this->team->id,
    ]);

    $this->server = Server::factory()->create([
        'team_id' => $this->team->id,
        'private_key_id' => $privateKey->id,
    ]);

    $this->destination = StandaloneDocker::withoutEvents(function () {
        return StandaloneDocker::create([
            'name' => 'Standalone Docker',
            'uuid' => (string) new Cuid2,
            'network' => 'coolify-'.new Cuid2,
            'server_id' => $this->server->id,
        ]);
    });

    $this->project = Project::create([
        'name' => 'Test Project',
        'team_id' => $this->team->id,
    ]);

    $this->environment = $this->project->environments->first();
});

test('application creation requires cpu and memory limits', function () {
    $response = $this->withHeaders([
        'Authorization' => 'Bearer '.$this->bearerToken,
        'Content-Type' => 'application/json',
    ])->postJson('/api/v1/applications/public', [
        'project_uuid' => $this->project->uuid,
        'environment_uuid' => $this->environment->uuid,
        'server_uuid' => $this->server->uuid,
        'destination_uuid' => $this->destination->uuid,
        'git_repository' => 'https://github.com/coollabsio/coolify',
        'git_branch' => 'main',
        'build_pack' => 'nixpacks',
        'ports_exposes' => '3000',
    ]);

    $response->assertStatus(422);
    $response->assertJsonValidationErrors(['limits_cpus', 'limits_memory']);
});

test('application creation succeeds when cpu and memory limits are provided', function () {
    $response = $this->withHeaders([
        'Authorization' => 'Bearer '.$this->bearerToken,
        'Content-Type' => 'application/json',
    ])->postJson('/api/v1/applications/public', [
        'project_uuid' => $this->project->uuid,
        'environment_uuid' => $this->environment->uuid,
        'server_uuid' => $this->server->uuid,
        'destination_uuid' => $this->destination->uuid,
        'git_repository' => 'https://github.com/coollabsio/coolify',
        'git_branch' => 'main',
        'build_pack' => 'nixpacks',
        'ports_exposes' => '3000',
        'limits_cpus' => '1',
        'limits_memory' => '1g',
    ]);

    $response->assertStatus(201);

    $application = Application::query()->latest('id')->first();
    expect($application->limits_cpus)->toBe('1');
    expect($application->limits_memory)->toBe('1g');
});
