<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class UserFactory extends Factory
{
    protected static ?string $password;

    public function definition(): array
    {
        return [
            'name' => $this->faker->name(),
            'email' => $this->faker->unique()->safeEmail(),
            'phone' => '010' . $this->faker->numerify('#######'),
            // A7 (H-2): a factory user is NEVER an administrator by
            // default — the old random 25% admin role meant a privileged
            // account could appear wherever a test (or a dev dump) called
            // User::factory(). Privileged actors must ask for it
            // explicitly with User::factory()->admin().
            'role' => 'user',
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
        ];
    }

    /**
     * A7 (H-2): the ONLY way to obtain an administrator from this
     * factory. Tests that need a privileged actor use it so the role is
     * stated at the call site instead of being inherited by chance.
     */
    public function admin(): static
    {
        return $this->state(fn () => ['role' => 'admin']);
    }

    /**
     * SEC-03: accounts that still need to confirm their email address.
     */
    public function unverified(): static
    {
        return $this->state(fn () => ['email_verified_at' => null]);
    }
}