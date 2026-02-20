<?php

namespace Database\Factories;

use App\Models\Service;
use App\Models\ServiceApplication;
use Illuminate\Database\Eloquent\Factories\Factory;

class ServiceApplicationFactory extends Factory
{
    protected $model = ServiceApplication::class;

    public function definition(): array
    {
        return [
            'service_id' => Service::factory(),
            'name' => fake()->unique()->domainWord().'-service-app',
            'human_name' => fake()->optional()->words(2, true),
            'description' => fake()->optional()->sentence(),
            'fqdn' => fake()->unique()->domainName(),
            'ports' => '80',
            'exposes' => '80',
            'status' => 'running:healthy',
        ];
    }
}
