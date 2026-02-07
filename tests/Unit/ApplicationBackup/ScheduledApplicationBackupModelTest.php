<?php

use App\Models\Application;
use App\Models\ScheduledApplicationBackup;
use App\Models\ScheduledApplicationBackupExecution;
use App\Models\S3Storage;
use App\Models\Team;
use App\Models\User;

beforeEach(function () {
    // Create test team and user
    $this->team = Team::factory()->create();
    $this->user = User::factory()->create([
        'current_team_id' => $this->team->id,
    ]);
    $this->team->users()->attach($this->user);
});

test('scheduled application backup belongs to a team', function () {
    $backup = ScheduledApplicationBackup::factory()->create([
        'team_id' => $this->team->id,
    ]);

    expect($backup->team)->toBeInstanceOf(Team::class);
    expect($backup->team->id)->toBe($this->team->id);
});

test('scheduled application backup has morphTo relationship with application', function () {
    $application = Application::factory()->create();
    $backup = ScheduledApplicationBackup::factory()->create([
        'application_id' => $application->id,
        'application_type' => Application::class,
    ]);

    expect($backup->application)->toBeInstanceOf(Application::class);
    expect($backup->application->id)->toBe($application->id);
});

test('scheduled application backup belongs to s3 storage', function () {
    $s3 = S3Storage::factory()->create();
    $backup = ScheduledApplicationBackup::factory()->create([
        's3_storage_id' => $s3->id,
    ]);

    expect($backup->s3)->toBeInstanceOf(S3Storage::class);
    expect($backup->s3->id)->toBe($s3->id);
});

test('scheduled application backup has many executions', function () {
    $backup = ScheduledApplicationBackup::factory()->create();
    $execution1 = ScheduledApplicationBackupExecution::factory()->create([
        'scheduled_application_backup_id' => $backup->id,
    ]);
    $execution2 = ScheduledApplicationBackupExecution::factory()->create([
        'scheduled_application_backup_id' => $backup->id,
    ]);

    expect($backup->executions)->toHaveCount(2);
    expect($backup->executions->first()->id)->toBe($execution2->id); // Ordered by desc
});

test('scheduled application backup has latest log relationship', function () {
    $backup = ScheduledApplicationBackup::factory()->create();
    $execution1 = ScheduledApplicationBackupExecution::factory()->create([
        'scheduled_application_backup_id' => $backup->id,
        'created_at' => now()->subDay(),
    ]);
    $execution2 = ScheduledApplicationBackupExecution::factory()->create([
        'scheduled_application_backup_id' => $backup->id,
        'created_at' => now(),
    ]);

    expect($backup->latest_log)->toBeInstanceOf(ScheduledApplicationBackupExecution::class);
    expect($backup->latest_log->id)->toBe($execution2->id);
});

test('scheduled application backup scope ownedByCurrentTeam', function () {
    $this->actingAs($this->user);

    $backup1 = ScheduledApplicationBackup::factory()->create([
        'team_id' => $this->team->id,
    ]);

    $backup2 = ScheduledApplicationBackup::factory()->create([
        'team_id' => Team::factory()->create()->id,
    ]);

    $backups = ScheduledApplicationBackup::ownedByCurrentTeam()->get();

    expect($backups)->toHaveCount(1);
    expect($backups->first()->id)->toBe($backup1->id);
});

test('scheduled application backup casts booleans correctly', function () {
    $backup = ScheduledApplicationBackup::factory()->create([
        'enabled' => true,
        'save_s3' => true,
    ]);

    expect($backup->enabled)->toBeBool();
    expect($backup->save_s3)->toBeBool();
    expect($backup->enabled)->toBeTrue();
    expect($backup->save_s3)->toBeTrue();
});

test('scheduled application backup server method returns server from application', function () {
    $application = Application::factory()->create();
    $backup = ScheduledApplicationBackup::factory()->create([
        'application_id' => $application->id,
        'application_type' => Application::class,
    ]);

    $server = $backup->server();

    expect($server)->not->toBeNull();
    expect($server)->toBe($application->destination->server);
});
