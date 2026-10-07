<?php

namespace Database\Factories;

use App\Core\Identity\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;

/**
 * Users belong to a tenant: create them inside a tenant context
 * (TenantContext::run), which fills tenant_id.
 *
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    protected $model = User::class;

    protected static ?string $password;

    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('violet-harbour-42'),
            'locale' => 'en',
            'status' => User::STATUS_ACTIVE,
        ];
    }

    public function unverified(): static
    {
        return $this->state(fn () => ['email_verified_at' => null, 'status' => User::STATUS_PENDING]);
    }
}
