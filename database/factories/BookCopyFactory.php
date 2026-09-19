<?php

namespace Database\Factories;

use App\Models\Book;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class BookCopyFactory extends Factory
{
    public function definition(): array
    {
        return [
            'uuid'            => Str::uuid(),
            'bookID'          => Book::factory(),
            'accessionNumber' => 'ACC-' . $this->faker->unique()->numerify('####-####'),
            'barcodeValue'    => 'BK-' . strtoupper($this->faker->unique()->bothify('??########')),
            'status'          => 'available',
        ];
    }

    public function borrowed(): static
    {
        return $this->state(fn () => ['status' => 'borrowed']);
    }

    public function lost(): static
    {
        return $this->state(fn () => ['status' => 'lost']);
    }

    public function damaged(): static
    {
        return $this->state(fn () => ['status' => 'damaged']);
    }

    public function retired(): static
    {
        return $this->state(fn () => ['status' => 'retired']);
    }
}
