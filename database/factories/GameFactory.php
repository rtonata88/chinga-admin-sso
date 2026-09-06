<?php

namespace Database\Factories;

use App\Models\Game;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class GameFactory extends Factory
{
    protected $model = Game::class;

    public function definition(): array
    {
        $name = ucfirst(fake()->unique()->words(2, true));

        return [
            'uuid' => Str::uuid()->toString(),
            'name' => $name,
            'slug' => Str::slug($name),
            'description' => fake()->sentence(),
            'type' => 'other',
            'status' => 'active',
            'version' => '1.0.0',
            'settings' => [],
        ];
    }

    public function fantasy(): static
    {
        return $this->state(fn () => [
            'name' => 'Chinga Fantasy',
            'slug' => 'chinga-fantasy',
            'type' => 'other',
        ]);
    }

    public function development(): static
    {
        return $this->state(fn () => ['status' => 'development']);
    }
}
