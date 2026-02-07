<?php

namespace Database\Factories;

use App\Models\S3Storage;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

class S3StorageFactory extends Factory
{
    protected $model = S3Storage::class;

    public function definition(): array
    {
        return [
            'uuid' => str()->uuid(),
            'name' => fake()->words(3, true),
            'description' => fake()->optional()->sentence(),
            'endpoint' => 'https://s3.' . fake()->randomElement(['us-east-1', 'eu-west-1', 'ap-southeast-1']) . '.amazonaws.com',
            'key' => fake()->uuid(),
            'secret' => fake()->uuid(),
            'region' => fake()->randomElement(['us-east-1', 'eu-west-1', 'ap-southeast-1']),
            'bucket' => fake()->domainWord() . '-backups',
            'path' => fake()->optional()->filePath(),
            'team_id' => Team::factory(),
            'is_usable' => true,
        ];
    }

    public function unusable(): self
    {
        return $this->state(fn (array $attributes) => [
            'is_usable' => false,
        ]);
    }
}
