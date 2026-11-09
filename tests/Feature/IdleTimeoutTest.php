<?php

namespace Tests\Feature;

use App\Http\Middleware\EndIdleSessions;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class IdleTimeoutTest extends TestCase
{
    use RefreshDatabase;

    private function signIn(): array
    {
        $user = User::factory()->create();
        $token = $user->createToken('auth_token');

        return [$token->plainTextToken, $token->accessToken->getKey()];
    }

    private function as(string $bearer, string $path = '/api/user')
    {
        // Each call is a fresh request, as in production (the guard caches the resolved user on the app instance).
        $this->app['auth']->forgetGuards();

        return $this->withHeaders(['Authorization' => "Bearer {$bearer}", 'Accept' => 'application/json'])->getJson($path);
    }

    public function test_an_active_sign_in_keeps_working(): void
    {
        [$bearer] = $this->signIn();

        $this->as($bearer)->assertOk();
        $this->as($bearer)->assertOk();
    }

    public function test_a_sign_in_unused_for_over_four_hours_is_ended(): void
    {
        [$bearer, $id] = $this->signIn();
        $this->as($bearer)->assertOk();

        Cache::put(EndIdleSessions::ACTIVITY_PREFIX.$id, now()->subHours(5)->timestamp);

        $this->as($bearer)->assertStatus(401)->assertJson(['reason' => 'idle']);
        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $id]);
        // The dead token keeps saying why, so the app can show the right message.
        $this->as($bearer)->assertStatus(401)->assertJson(['reason' => 'idle']);
    }

    public function test_use_inside_the_window_resets_the_clock(): void
    {
        [$bearer, $id] = $this->signIn();
        $this->as($bearer)->assertOk();

        Cache::put(EndIdleSessions::ACTIVITY_PREFIX.$id, now()->subHours(3)->timestamp);
        $this->as($bearer)->assertOk();

        $this->assertGreaterThan(now()->subMinute()->timestamp, (int) Cache::get(EndIdleSessions::ACTIVITY_PREFIX.$id));
    }

    public function test_the_background_session_check_does_not_count_as_use(): void
    {
        [$bearer, $id] = $this->signIn();
        $this->as($bearer)->assertOk();

        $old = now()->subHours(3)->timestamp;
        Cache::put(EndIdleSessions::ACTIVITY_PREFIX.$id, $old);

        $this->as($bearer, '/api/session')->assertOk();

        $this->assertSame($old, (int) Cache::get(EndIdleSessions::ACTIVITY_PREFIX.$id));
    }

    public function test_a_sign_in_with_no_record_gets_a_fresh_start(): void
    {
        [$bearer, $id] = $this->signIn();

        $this->as($bearer)->assertOk();

        $this->assertNotNull(Cache::get(EndIdleSessions::ACTIVITY_PREFIX.$id));
    }
}
