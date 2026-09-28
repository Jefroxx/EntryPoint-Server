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
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'uuid'          => Str::uuid(),
            'firstName'     => fake()->firstName(),
            'middleInitial' => strtoupper(fake()->randomLetter()),
            'lastName'      => fake()->lastName(),
            'email'           => fake()->unique()->safeEmail(),
            'emailVerifiedAt' => now(),
            'password'      => static::$password ??= Hash::make('password'),
            'phoneNumber'   => '09' . fake()->numerify('#########'),
            'birthDate'     => fake()->date(),
            'address'       => fake()->city(),
            'userType'      => 'student',
        ];
    }
}
