<?php

namespace Tests\Feature;

use App\Models\Librarian;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

/** A librarian's phone used as the desk's camera: pairing by QR, a limited token, and a queue of scanned barcodes. */
class PhoneScannerTest extends TestCase
{
    use RefreshDatabase;

    private User $librarian;

    protected function setUp(): void
    {
        parent::setUp();

        $this->librarian = User::factory()->create(['userType' => 'librarian', 'password' => Hash::make('password123')]);
        Librarian::create(['librarianID' => $this->librarian->userID, 'uuid' => Str::uuid(), 'role' => 'librarian']);
    }

    /** The desk shows a QR, the phone opens it: returns the phone's bearer token. */
    private function pairPhone(?User $librarian = null): string
    {
        $code = $this->actingAs($librarian ?? $this->librarian)->postJson('/api/librarian/phone-pairing')->assertOk()->json('code');
        app('auth')->forgetGuards();

        return $this->postJson('/api/phone-scanner/claim', ['code' => $code])->assertOk()->json('token');
    }

    private function asPhone(string $token)
    {
        app('auth')->forgetGuards();

        return $this->withToken($token);
    }

    public function test_the_pairing_names_the_address_the_phone_should_open(): void
    {
        config(['portal.url' => 'https://entrypoint.digital']);

        $this->actingAs($this->librarian)->postJson('/api/librarian/phone-pairing')
            ->assertOk()->assertJsonPath('portalUrl', 'https://entrypoint.digital');
    }

    public function test_the_desk_gets_a_code_and_the_phone_trades_it_once_for_a_limited_token(): void
    {
        $code = $this->actingAs($this->librarian)->postJson('/api/librarian/phone-pairing')
            ->assertOk()->assertJsonStructure(['code', 'expiresAt', 'portalUrl'])->json('code');
        app('auth')->forgetGuards();

        $claimed = $this->postJson('/api/phone-scanner/claim', ['code' => $code])
            ->assertOk()->assertJsonStructure(['token', 'expiresAt', 'name']);
        $this->assertSame($this->librarian->fullName, $claimed->json('name'));

        $token = $this->librarian->tokens()->where('name', 'phone-scanner')->firstOrFail();
        $this->assertSame(['scan-relay'], $token->abilities);
        $this->assertNotNull($token->expires_at);

        // The same code is useless a second time.
        $this->postJson('/api/phone-scanner/claim', ['code' => $code])->assertUnprocessable()->assertJsonValidationErrors('code');
    }

    public function test_a_code_that_was_never_issued_or_has_expired_is_refused(): void
    {
        $this->postJson('/api/phone-scanner/claim', ['code' => 'not-a-real-code'])->assertUnprocessable();

        $code = $this->actingAs($this->librarian)->postJson('/api/librarian/phone-pairing')->json('code');
        app('auth')->forgetGuards();
        $this->travel(11)->minutes();
        $this->postJson('/api/phone-scanner/claim', ['code' => $code])->assertUnprocessable();
    }

    public function test_only_a_librarian_can_ask_for_a_code(): void
    {
        $student = Student::factory()->create(['registrationStatus' => 'approved']);

        $this->actingAs($student->user)->postJson('/api/librarian/phone-pairing')->assertForbidden();
    }

    public function test_the_phones_token_can_send_scans_and_nothing_else(): void
    {
        $token = $this->pairPhone();

        $this->asPhone($token)->postJson('/api/phone-scanner/scan', ['code' => 'STI-123'])->assertOk();

        // Every ordinary route turns the limited token away, including ones that only ask "is someone signed in?".
        foreach (['/api/librarian/loans', '/api/librarian/me', '/api/notifications', '/api/user', '/api/session', '/api/books'] as $url) {
            $this->asPhone($token)->getJson($url)->assertForbidden();
        }
        $this->asPhone($token)->postJson('/api/logout')->assertForbidden();
    }

    private function poll(string $as = 'desk')
    {
        app('auth')->forgetGuards();

        return $this->actingAs($this->librarian)->getJson("/api/librarian/phone-scans?as={$as}")->assertOk();
    }

    public function test_the_desk_gets_each_scan_once_and_in_order(): void
    {
        $token = $this->pairPhone();
        $this->asPhone($token)->postJson('/api/phone-scanner/scan', ['code' => 'ACC-0001'])->assertOk();
        $this->asPhone($token)->postJson('/api/phone-scanner/scan', ['code' => 'ACC-0002'])->assertOk();

        $this->assertSame(['ACC-0001', 'ACC-0002'], array_column($this->poll()->json('scans'), 'code'));

        // Taking them emptied the queue: asking again (a second tab, or the next second) gets nothing, so a scan can
        // never be acted on twice.
        $this->assertSame([], $this->poll()->json('scans'));
    }

    public function test_two_stations_never_both_get_the_same_scan(): void
    {
        $token = $this->pairPhone();
        $this->asPhone($token)->postJson('/api/phone-scanner/scan', ['code' => 'STI-1'])->assertOk();

        $first = $this->poll('station')->json('scans');
        $second = $this->poll('station')->json('scans');

        $this->assertCount(1, $first);
        $this->assertCount(0, $second);
    }

    public function test_a_checkout_or_start_session_window_comes_before_the_station(): void
    {
        $token = $this->pairPhone();

        // A desk window asked a moment ago: the Station is given nothing, and the scan waits for the window.
        $this->poll('desk');
        $this->asPhone($token)->postJson('/api/phone-scanner/scan', ['code' => 'BK-1'])->assertOk();
        $this->assertSame([], $this->poll('station')->json('scans'));
        $this->assertSame(['BK-1'], array_column($this->poll('desk')->json('scans'), 'code'));

        // Once the window has gone quiet for a few seconds, the Station is served again.
        $this->travel(6)->seconds();
        $this->asPhone($token)->postJson('/api/phone-scanner/scan', ['code' => 'STI-2'])->assertOk();
        $this->assertSame(['STI-2'], array_column($this->poll('station')->json('scans'), 'code'));
    }

    public function test_a_scan_nobody_picked_up_in_time_is_dropped(): void
    {
        $token = $this->pairPhone();
        $this->asPhone($token)->postJson('/api/phone-scanner/scan', ['code' => 'OLD'])->assertOk();

        $this->travel(20)->seconds();

        $this->assertSame([], $this->poll()->json('scans'));
    }

    public function test_the_as_value_must_be_desk_or_station(): void
    {
        $this->actingAs($this->librarian)->getJson('/api/librarian/phone-scans?as=everyone')->assertUnprocessable();
    }

    public function test_each_librarian_has_their_own_queue(): void
    {
        $other = User::factory()->create(['userType' => 'librarian']);
        Librarian::create(['librarianID' => $other->userID, 'uuid' => Str::uuid(), 'role' => 'librarian']);

        $mine = $this->pairPhone();
        $this->asPhone($mine)->postJson('/api/phone-scanner/scan', ['code' => 'MINE'])->assertOk();

        app('auth')->forgetGuards();
        $this->actingAs($other)->getJson('/api/librarian/phone-scans')->assertOk()->assertJsonCount(0, 'scans');
    }

    public function test_the_desk_sees_whether_a_phone_is_connected(): void
    {
        $this->actingAs($this->librarian)->getJson('/api/librarian/phone-scans')->assertJson(['phoneConnected' => false]);

        $token = $this->pairPhone();
        app('auth')->forgetGuards();
        $this->actingAs($this->librarian)->getJson('/api/librarian/phone-scans')->assertJson(['phoneConnected' => true]);

        // A phone that has gone quiet is no longer shown as connected; its ping brings it back.
        $this->travel(30)->seconds();
        app('auth')->forgetGuards();
        $this->actingAs($this->librarian)->getJson('/api/librarian/phone-scans')->assertJson(['phoneConnected' => false]);

        $this->asPhone($token)->getJson('/api/phone-scanner/ping')->assertOk();
        app('auth')->forgetGuards();
        $this->actingAs($this->librarian)->getJson('/api/librarian/phone-scans')->assertJson(['phoneConnected' => true]);
    }

    public function test_a_scan_must_be_a_short_non_empty_code_and_the_queue_keeps_only_the_newest(): void
    {
        $token = $this->pairPhone();

        $this->asPhone($token)->postJson('/api/phone-scanner/scan', ['code' => ''])->assertUnprocessable();
        $this->asPhone($token)->postJson('/api/phone-scanner/scan', ['code' => str_repeat('x', 201)])->assertUnprocessable();

        foreach (range(1, 60) as $n) {
            $this->asPhone($token)->postJson('/api/phone-scanner/scan', ['code' => "C{$n}"])->assertOk();
        }

        app('auth')->forgetGuards();
        $scans = $this->actingAs($this->librarian)->getJson('/api/librarian/phone-scans')->json('scans');
        $this->assertCount(50, $scans);
        $this->assertSame('C60', end($scans)['code']);
    }

    public function test_the_phone_can_leave_and_the_desk_can_disconnect_every_phone(): void
    {
        $first = $this->pairPhone();
        $second = $this->pairPhone();

        $this->asPhone($first)->deleteJson('/api/phone-scanner')->assertOk();
        $this->assertSame(1, $this->librarian->tokens()->where('name', 'phone-scanner')->count());

        app('auth')->forgetGuards();
        $this->actingAs($this->librarian)->deleteJson('/api/librarian/phone-pairing')->assertOk();
        $this->assertSame(0, $this->librarian->tokens()->where('name', 'phone-scanner')->count());
    }

    public function test_signing_out_of_the_desk_signs_the_phones_out_too(): void
    {
        $desk = $this->librarian->createToken('auth_token')->plainTextToken;
        $this->pairPhone();
        $this->assertSame(1, $this->librarian->tokens()->where('name', 'phone-scanner')->count());

        app('auth')->forgetGuards();
        $this->withToken($desk)->postJson('/api/logout')->assertOk();

        $this->assertSame(0, $this->librarian->tokens()->count());
    }

    public function test_a_disabled_account_can_no_longer_scan(): void
    {
        $token = $this->pairPhone();
        $this->librarian->update(['disabledAt' => now()]);

        $this->asPhone($token)->postJson('/api/phone-scanner/scan', ['code' => 'X'])->assertForbidden();
        $this->assertSame(0, $this->librarian->tokens()->where('name', 'phone-scanner')->count());
    }

    public function test_the_phones_token_stops_working_after_twelve_hours(): void
    {
        $token = $this->pairPhone();

        $this->travel(13)->hours();
        $this->asPhone($token)->postJson('/api/phone-scanner/scan', ['code' => 'LATE'])->assertUnauthorized();
    }
}
