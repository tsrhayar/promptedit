<?php

namespace Database\Factories;

use App\Models\Generation;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Generation>
 */
class GenerationFactory extends Factory
{
    public function definition(): array
    {
        return [
            'uuid' => (string) Str::uuid(),
            'user_id' => User::factory(),
            'provider_id' => null,
            'prompt' => 'A lighthouse on a cliff at dawn, oil painting',
            'size' => '1024x1024',
            'quality' => 'medium',
            'reference_images' => [],
            'status' => Generation::PENDING,
            'estimated_min_nano' => 58_175_000,
            'estimated_max_nano' => 86_325_000,
        ];
    }

    public function queued(): static
    {
        return $this->state(fn () => [
            'status' => Generation::QUEUED,
            'provider_id' => 'gen_'.Str::upper(Str::random(26)),
        ]);
    }
}
