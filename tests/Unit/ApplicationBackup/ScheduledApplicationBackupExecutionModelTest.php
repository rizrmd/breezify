<?php

use App\Models\ScheduledApplicationBackup;
use App\Models\ScheduledApplicationBackupExecution;

test('scheduled application backup execution belongs to backup', function () {
    $backup = ScheduledApplicationBackup::factory()->create();
    $execution = ScheduledApplicationBackupExecution::factory()->create([
        'scheduled_application_backup_id' => $backup->id,
    ]);

    expect($execution->scheduledApplicationBackup)->toBeInstanceOf(ScheduledApplicationBackup::class);
    expect($execution->scheduledApplicationBackup->id)->toBe($backup->id);
});

test('scheduled application backup execution casts booleans correctly', function () {
    $execution = ScheduledApplicationBackupExecution::factory()->create([
        's3_uploaded' => true,
        's3_storage_deleted' => false,
    ]);

    expect($execution->s3_uploaded)->toBeBool();
    expect($execution->s3_storage_deleted)->toBeBool();
    expect($execution->s3_uploaded)->toBeTrue();
    expect($execution->s3_storage_deleted)->toBeFalse();
});

test('scheduled application backup execution casts metadata to array', function () {
    $metadata = [
        'application_name' => 'Test App',
        'file_volumes_count' => 3,
    ];

    $execution = ScheduledApplicationBackupExecution::factory()->create([
        'metadata' => $metadata,
    ]);

    expect($execution->metadata)->toBeArray();
    expect($execution->metadata['application_name'])->toBe('Test App');
    expect($execution->metadata['file_volumes_count'])->toBe(3);
});

test('scheduled application backup execution status can be success', function () {
    $execution = ScheduledApplicationBackupExecution::factory()->create([
        'status' => 'success',
    ]);

    expect($execution->status)->toBe('success');
});

test('scheduled application backup execution status can be failed', function () {
    $execution = ScheduledApplicationBackupExecution::factory()->create([
        'status' => 'failed',
    ]);

    expect($execution->status)->toBe('failed');
});

test('scheduled application backup execution status can be running', function () {
    $execution = ScheduledApplicationBackupExecution::factory()->create([
        'status' => 'running',
    ]);

    expect($execution->status)->toBe('running');
});
