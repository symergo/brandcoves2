<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\AnonymousIdentity;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AnonymousIdentity>
 */
class AnonymousIdentityFactory extends Factory
{
    protected $model = AnonymousIdentity::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return ['last_seen_at' => now()];
    }
}
