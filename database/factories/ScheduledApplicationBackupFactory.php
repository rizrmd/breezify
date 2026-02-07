<?php

namespace Database\Factories;

use App\Models\Application;
use App\Models\S3Storage;
use App\Models\ScheduledApplicationBackup;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

class ScheduledApplicationBackupFactory extends Factory
{
    protected $model = ScheduledApplicationBackup::class;

    public function definition(): array
    {
        return [
            'uuid' => str()->uuid(),
            'description' => fake()->sentence(),
            'enabled' => true,
            'save_s3' => true,
            'frequency' => '0 2 * * *', // Daily at 2 AM
            'application_id' => Application::factory(),
            'application_type' => Application::class,
            's3_storage_id' => S3Storage::factory(),
            'team_id' => Team::factory(),
            'application_backup_retention_amount_s3' => fake()->numberBetween(0, 10),
            'application_backup_retention_days_s3' => fake()->numberBetween(0, 30),
            'application_backup_retention_max_storage_s3' => fake()->randomFloat(1, 0, 100),
        ];
    }

    public function disabled(): self
    {
        return $this->state(fn (array $attributes) => [
            'enabled' => false,
        ]);
    }

    public function withFrequency(string $frequency): self
    {
        return $this->state(fn (array $attributes) => [
            'frequency' => $frequency,
        ]);
    }
}
