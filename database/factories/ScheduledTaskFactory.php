<?php

namespace Database\Factories;

use App\Models\ScheduledTask;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class ScheduledTaskFactory extends Factory
{
    protected $model = ScheduledTask::class;

    public function definition(): array
    {
        return [
            'uuid' => (string) Str::uuid(),
            'name' => 'task-'.fake()->unique()->word(),
            'command' => 'echo "Hello from Coolify"',
            'frequency' => '* * * * *',
            'enabled' => true,
            'team_id' => Team::factory(),
            'timeout' => 60,
        ];
    }
}
