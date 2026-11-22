<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** The middle initial is one letter, and the dot after it is added only when the name is shown. */
class MiddleInitialTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_stored_initial_with_a_dot_is_shown_with_one_dot(): void
    {
        $user = User::factory()->make(['firstName' => 'Matt Oliver', 'middleInitial' => 'U.', 'lastName' => 'Pojadas']);
        $this->assertSame('Matt Oliver U. Pojadas', $user->fullName);

        $user->middleInitial = 'U';
        $this->assertSame('Matt Oliver U. Pojadas', $user->fullName);

        $user->middleInitial = null;
        $this->assertSame('Matt Oliver Pojadas', $user->fullName);

        $user->middleInitial = '.';
        $this->assertSame('Matt Oliver Pojadas', $user->fullName);
    }

    public function test_registration_takes_a_single_letter_only(): void
    {
        $payload = [
            'firstName' => 'Matt', 'lastName' => 'Pojadas', 'password' => 'password123', 'password_confirmation' => 'password123',
            'memberIDNumber' => '02-0003-69929', 'email' => 'matt.pojadas@davao.sti.edu.ph',
        ];

        $this->postJson('/api/register', $payload + ['middleInitial' => 'U.'])
            ->assertStatus(422)->assertJsonValidationErrors('middleInitial');
    }
}
