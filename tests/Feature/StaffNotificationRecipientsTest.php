<?php

namespace Tests\Feature;

use App\Models\Librarian;
use App\Models\SystemNotification;
use App\Models\User;
use App\Services\NotificationService;
use App\Services\StaffPermissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Desk notices go only to staff whose role covers that area, and never to the administrator. */
class StaffNotificationRecipientsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $librarian;
    private User $assistant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->staff('admin');
        $this->librarian = $this->staff('librarian');
        $this->assistant = $this->staff('assistant'); // default: circulation + attendance only
    }

    private function staff(string $role): User
    {
        $account = User::factory()->create(['userType' => 'librarian']);
        Librarian::create(['librarianID' => $account->userID, 'uuid' => Str::uuid(), 'role' => $role]);

        return $account;
    }

    private function recipients(string $type): array
    {
        app(NotificationService::class)->notifyAllLibrarians('A notice', $type);

        return SystemNotification::where('type', $type)->pluck('userID')->sort()->values()->all();
    }

    public function test_a_circulation_notice_reaches_the_librarian_and_the_assistant_but_not_the_admin(): void
    {
        $this->assertSame(
            collect([$this->librarian->userID, $this->assistant->userID])->sort()->values()->all(),
            $this->recipients('new_reservation'),
        );
    }

    public function test_a_registration_notice_skips_an_assistant_without_the_students_area(): void
    {
        $this->assertSame([$this->librarian->userID], $this->recipients('new_registration'));
    }

    public function test_the_roles_screen_decides_who_gets_a_notice(): void
    {
        // The administrator gives assistants the library area: book suggestions now reach them too.
        app(StaffPermissionService::class)->set('assistant', ['circulation', 'attendance', 'library']);

        $this->assertSame(
            collect([$this->librarian->userID, $this->assistant->userID])->sort()->values()->all(),
            $this->recipients('new_book_suggestion'),
        );
    }

    public function test_an_unmapped_notice_still_reaches_every_non_admin_staff_account(): void
    {
        $this->assertNotContains($this->admin->userID, $this->recipients('something_new'));
        $this->assertCount(2, SystemNotification::where('type', 'something_new')->get());
    }
}
