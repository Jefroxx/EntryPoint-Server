<?php

namespace Tests\Feature;

use App\Models\Librarian;
use App\Models\User;
use App\Support\Uploads;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** What a hosted copy needs: the first administrator without a shell, pictures kept in a bucket, and CORS from settings. */
class HostingSetupTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_first_administrator_is_created_once_and_must_change_the_password(): void
    {
        config(['hosting.admin_email' => 'admin@school.edu.ph', 'hosting.admin_password' => 'a-long-password']);

        $this->artisan('entrypoint:setup')->assertSuccessful();
        $this->artisan('entrypoint:setup')->assertSuccessful();

        $user = User::where('email', 'admin@school.edu.ph')->firstOrFail();
        $this->assertSame(1, User::where('email', 'admin@school.edu.ph')->count());
        $this->assertSame('admin', Librarian::findOrFail($user->userID)->role);
        $this->assertTrue($user->mustChangePassword);
        $this->assertNotNull($user->emailVerifiedAt);

        $this->postJson('/api/librarian/login', ['email' => 'admin@school.edu.ph', 'password' => 'a-long-password'])->assertOk();
    }

    public function test_a_short_password_does_not_create_the_administrator(): void
    {
        config(['hosting.admin_email' => 'admin@school.edu.ph', 'hosting.admin_password' => 'short']);

        $this->artisan('entrypoint:setup')->assertFailed();
        $this->assertSame(0, User::count());
    }

    public function test_nothing_happens_without_settings_and_the_sample_data_never_goes_into_a_used_database(): void
    {
        $this->artisan('entrypoint:setup')->assertSuccessful();
        $this->assertSame(0, User::count());

        User::factory()->create();
        config(['hosting.seed_demo' => true]);
        $this->artisan('entrypoint:setup')->assertSuccessful();
        $this->assertSame(1, User::count());
    }

    public function test_pictures_get_the_bucket_address_when_a_bucket_is_set_and_the_request_address_otherwise(): void
    {
        $this->assertStringEndsWith('/storage/market-items/a.jpg', Uploads::url('market-items/a.jpg'));

        config([
            'filesystems.disks.public' => ['driver' => 's3', 'key' => 'k', 'secret' => 's', 'region' => 'auto', 'bucket' => 'entrypoint',
                'endpoint' => 'https://example.r2.cloudflarestorage.com', 'url' => 'https://pub-123.r2.dev', 'use_path_style_endpoint' => true],
        ]);
        app('filesystem')->forgetDisk('public');

        $this->assertSame('https://pub-123.r2.dev/market-items/a.jpg', Uploads::url('market-items/a.jpg'));
    }

    public function test_the_allowed_origins_come_from_the_setting(): void
    {
        $origins = require base_path('config/cors.php');

        $this->assertContains('http://localhost:3000', $origins['allowed_origins']);
    }
}
