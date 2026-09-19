<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class StudentFactory extends Factory
{
    public function definition(): array
    {
        $first = $this->faker->firstName();
        $last = $this->faker->lastName();

        return [
            'studentID' => User::factory()->state([
                'firstName'     => $first,
                'lastName'      => $last,
                'middleInitial' => strtoupper($this->faker->randomLetter()),
                'email'         => Str::of($first . '.' . $last)->slug('.') . '@davao.sti.edu.ph',
                'password'      => Hash::make('password123'),
                'phoneNumber'   => '09' . $this->faker->numerify('#########'),
                'birthDate'     => $this->faker->dateTimeBetween('-24 years', '-17 years')->format('Y-m-d'),
                'address'       => $this->faker->city() . ', Davao del Sur',
                'userType'      => 'student',
            ]),
            'uuid'                   => Str::uuid(),
            'studentIDNumber'        => $this->faker->unique()->numerify('##-####-###'),
            'barcodeValue'           => null,
            'academicProgram'        => $this->faker->randomElement([
                'BS Information Technology', 'BS Computer Science', 'BS Accountancy',
                'BS Nursing', 'BS Civil Engineering', 'BS Education', 'BS Psychology',
                'BS Business Administration', 'BS Hospitality Management', 'BS Criminology',
            ]),
            'knowledgeScore'         => 0,
            'visitStreak'            => 0,
            'registrationStatus'     => 'pending',
            'reviewedByLibrarianID'  => null,
            'reviewedAt'             => null,
        ];
    }

    public function approved(): static
    {
        return $this->state(fn () => [
            'registrationStatus' => 'approved',
            'barcodeValue'       => 'STI-' . strtoupper($this->faker->unique()->bothify('##########')),
            'reviewedAt'         => now(),
        ]);
    }

    public function rejected(): static
    {
        return $this->state(fn () => [
            'registrationStatus' => 'rejected',
            'reviewedAt'         => now(),
        ]);
    }
}
