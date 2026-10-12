<?php

namespace Tests\Feature;

use App\Models\Announcement;
use App\Models\AuditLog;
use App\Models\Librarian;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Notices from the administrator: managed on the admin side, read by whichever side they are meant for.
 */
class AnnouncementsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->staff('admin');
    }

    private function staff(string $role): User
    {
        $user = User::factory()->create(['userType' => 'librarian']);
        Librarian::create(['librarianID' => $user->userID, 'uuid' => Str::uuid(), 'role' => $role]);

        return $user;
    }

    private function notice(string $title, string $audience = 'everyone', ?string $expiresAt = null): Announcement
    {
        return Announcement::create([
            'uuid' => Str::uuid(), 'title' => $title, 'body' => 'Details about ' . $title, 'audience' => $audience, 'expiresAt' => $expiresAt,
        ]);
    }

    private function titles($response): array
    {
        return array_column($response->assertOk()->json('announcements'), 'title');
    }

    public function test_the_admin_posts_edits_and_removes_announcements_and_each_is_logged(): void
    {
        $created = $this->actingAs($this->admin)->postJson('/api/admin/announcements', [
            'title' => 'Closed on Friday', 'body' => 'The library closes at noon.', 'audience' => 'everyone', 'expiresAt' => null,
        ])->assertCreated()->assertJsonPath('announcement.title', 'Closed on Friday')->assertJsonPath('announcement.isActive', true);

        $uuid = $created->json('announcement.uuid');

        $this->actingAs($this->admin)->patchJson("/api/admin/announcements/{$uuid}", [
            'title' => 'Closed on Saturday', 'body' => 'The library closes at noon.', 'audience' => 'students',
        ])->assertOk()->assertJsonPath('announcement.audience', 'students');

        $this->assertSame(['Closed on Saturday'], $this->titles($this->actingAs($this->admin)->getJson('/api/admin/announcements')));

        $this->actingAs($this->admin)->deleteJson("/api/admin/announcements/{$uuid}")->assertOk();
        $this->assertSame(0, Announcement::count());

        $this->assertSame(['announcements.posted', 'announcements.edited', 'announcements.removed'], AuditLog::orderBy('logID')->pluck('action')->all());
    }

    public function test_an_announcement_needs_a_title_a_message_and_a_real_audience(): void
    {
        $this->actingAs($this->admin)->postJson('/api/admin/announcements', [])->assertJsonValidationErrors(['title', 'body', 'audience']);
        $this->actingAs($this->admin)->postJson('/api/admin/announcements', ['title' => 'x', 'body' => 'y', 'audience' => 'martians'])->assertJsonValidationErrors('audience');
        $this->actingAs($this->admin)->postJson('/api/admin/announcements', ['title' => str_repeat('a', 121), 'body' => 'y', 'audience' => 'staff'])->assertJsonValidationErrors('title');
    }

    public function test_only_administrators_manage_announcements(): void
    {
        $announcement = $this->notice('Notice');

        foreach ([$this->staff('librarian'), $this->staff('assistant'), Student::factory()->approved()->create()->user] as $user) {
            $this->actingAs($user)->getJson('/api/admin/announcements')->assertForbidden();
            $this->actingAs($user)->postJson('/api/admin/announcements', ['title' => 'x', 'body' => 'y', 'audience' => 'everyone'])->assertForbidden();
            $this->actingAs($user)->deleteJson("/api/admin/announcements/{$announcement->uuid}")->assertForbidden();
        }
    }

    public function test_each_side_sees_only_what_is_meant_for_it(): void
    {
        $this->notice('For all');
        $this->notice('Students and members only', 'students');
        $this->notice('Staff only', 'staff');

        $student = Student::factory()->approved()->create();
        $faculty = Student::factory()->faculty()->approved()->create();

        $this->assertEqualsCanonicalizing(['For all', 'Students and members only'], $this->titles($this->actingAs($student->user)->getJson('/api/student/announcements')));
        // Faculty and staff members use the student side, so they read the same notices.
        $this->assertEqualsCanonicalizing(['For all', 'Students and members only'], $this->titles($this->actingAs($faculty->user)->getJson('/api/student/announcements')));

        foreach (['librarian', 'assistant', 'admin'] as $role) {
            $this->assertEqualsCanonicalizing(['For all', 'Staff only'], $this->titles($this->actingAs($this->staff($role))->getJson('/api/librarian/announcements')));
        }
    }

    public function test_expired_announcements_disappear_from_both_sides_but_stay_on_the_admin_list(): void
    {
        $this->notice('Still on', 'everyone', now()->addDay()->toDateTimeString());
        $this->notice('Over', 'everyone', now()->subDay()->toDateTimeString());
        $this->notice('No end date');

        $student = Student::factory()->approved()->create();

        $this->assertEqualsCanonicalizing(['Still on', 'No end date'], $this->titles($this->actingAs($student->user)->getJson('/api/student/announcements')));
        $this->assertEqualsCanonicalizing(['Still on', 'No end date'], $this->titles($this->actingAs($this->staff('librarian'))->getJson('/api/librarian/announcements')));

        $all = $this->actingAs($this->admin)->getJson('/api/admin/announcements')->assertOk()->json('announcements');
        $this->assertCount(3, $all);
        $this->assertSame([false], array_values(array_unique(array_column(array_filter($all, fn ($a) => $a['title'] === 'Over'), 'isActive'))));
    }

    public function test_the_feeds_show_the_newest_five_and_need_a_signed_in_account_of_that_side(): void
    {
        foreach (range(1, 7) as $n) {
            $this->notice("Notice {$n}");
        }

        $student = Student::factory()->approved()->create();
        $feed = $this->titles($this->actingAs($student->user)->getJson('/api/student/announcements'));

        $this->assertCount(5, $feed);
        $this->assertSame('Notice 7', $feed[0]);

        $this->app['auth']->forgetGuards(); // the test app keeps the user from the request above
        $this->getJson('/api/student/announcements')->assertUnauthorized();
        // A student cannot read the staff feed, nor staff the student one.
        $this->actingAs($student->user)->getJson('/api/librarian/announcements')->assertForbidden();
        $this->actingAs($this->staff('librarian'))->getJson('/api/student/announcements')->assertForbidden();
    }
}
