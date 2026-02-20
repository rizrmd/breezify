<?php

namespace Database\Factories;

use App\Models\ScheduledDatabaseBackup;
use App\Models\StandalonePostgresql;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

class ScheduledDatabaseBackupFactory extends Factory
{
    protected $model = ScheduledDatabaseBackup::class;

    public function definition(): array
    {
        return [
            'description' => fake()->optional()->sentence(),
            'enabled' => true,
            'save_s3' => false,
            'frequency' => '0 * * * *',
            'number_of_backups_locally' => 3,
            'team_id' => Team::factory(),
        ];
    }

    public function configure(): static
    {
        return $this->afterMaking(function (ScheduledDatabaseBackup $backup) {
            if (! $backup->database_id || ! $backup->database_type) {
                $database = StandalonePostgresql::factory()->create();
                $backup->database_id = $database->id;
                $backup->database_type = $database->getMorphClass();
                $backup->team_id = $database->environment->project->team_id;
            } else {
                $backup->team_id = $backup->team_id ?? Team::factory()->create()->id;
            }
        });
    }
}
