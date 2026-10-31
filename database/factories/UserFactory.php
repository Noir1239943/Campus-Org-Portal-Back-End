<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    protected static ?string $password;

    public function definition(): array
    {
        return [
            'id_number' => '2025-'.str_pad((string) fake()->unique()->numberBetween(1, 99999), 5, '0', STR_PAD_LEFT),
            'first_name' => fake()->firstName(),
            'last_name' => fake()->lastName(),
            'email' => fake()->unique()->safeEmail(),
            'password' => static::$password ??= Hash::make('password123'),
            'role' => User::ROLE_STUDENT,
            'program' => fake()->randomElement(['BSIT', 'BEEd', 'BSEd', 'BSBA', 'BSHM']),
            'year_level' => fake()->numberBetween(1, 4),
            'remember_token' => Str::random(10),
        ];
    }

    public function adviser(): static
    {
        return $this->state(fn () => [
            'id_number' => 'FAC-'.fake()->unique()->numberBetween(1000, 9999),
            'role' => User::ROLE_ADVISER,
            'program' => null,
            'year_level' => null,
            'title' => 'Faculty Adviser',
        ]);
    }
}
