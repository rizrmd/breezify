<?php

namespace Database\Factories;

use App\Models\ScheduledApplicationBackup;
use App\Models\ScheduledApplicationBackupExecution;
use Illuminate\Database\Eloquent\Factories\Factory;

class ScheduledApplicationBackupExecutionFactory extends Factory
{
    protected $model = ScheduledApplicationBackupExecution::class;

    public function definition(): array
    {
        return [
            'uuid' => str()->uuid(),
            'status' => 'success',
            'message' => fake()->optional()->sentence(),
            'size' => fake()->randomNumber(8), // Random size in bytes
            'filename' => fake()->filePath(),
            'checksum' => fake()->sha256(),
            'metadata' => [
                'application_name' => fake()->words(3, true),
                'file_volumes_count' => fake()->numberBetween(0, 10),
            ],
            's3_uploaded' => true,
            's3_storage_deleted' => false,
            'scheduled_application_backup_id' => ScheduledApplicationBackup::factory(),
            'finished_at' => fake()->optional()->dateTime(),
        ];
    }

    public function success(): self
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'success',
        ]);
    }

    public function failed(): self
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'failed',
        ]);
    }

    public function running(): self
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'running',
        ]);
    }

    public function withoutS3(): self
    {
        return $this->state(fn (array $attributes) => [
            's3_uploaded' => false,
        ]);
    }
}
