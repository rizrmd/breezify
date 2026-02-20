<?php

namespace Database\Factories;

use App\Models\PrivateKey;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use phpseclib3\Crypt\RSA;

class PrivateKeyFactory extends Factory
{
    protected $model = PrivateKey::class;

    public function definition(): array
    {
        $keyPair = RSA::createKey(2048);

        return [
            'uuid' => (string) Str::uuid(),
            'name' => 'ssh-key-'.Str::random(6),
            'description' => fake()->optional()->sentence(),
            'private_key' => (string) $keyPair,
            'is_git_related' => false,
            'team_id' => Team::factory(),
        ];
    }
}
