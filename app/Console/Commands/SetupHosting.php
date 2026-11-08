<?php

namespace App\Console\Commands;

use App\Models\Librarian;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Runs when a hosted copy starts (see docker/entrypoint.sh). A host without a shell cannot run "db:seed" by hand, so
 * the two first-time jobs are driven by settings instead:
 *   - SEED_DEMO=true     fills an EMPTY database with the sample data (accounts with a known password: demos only).
 *   - ADMIN_EMAIL        creates that administrator if it does not exist yet, using ADMIN_PASSWORD, and makes the
 *                        account change the password at the first sign-in.
 * Both are safe to leave on: they do nothing once the data is there.
 */
class SetupHosting extends Command
{
    protected $signature = 'entrypoint:setup';

    protected $description = 'First-time setup of a hosted copy: optional sample data and the first administrator.';

    public function handle(): int
    {
        if (config('hosting.seed_demo') && User::count() === 0) {
            $this->info('Empty database: loading the sample data…');
            Artisan::call('db:seed', ['--force' => true]);
            $this->line(trim(Artisan::output()));
        }

        $email = trim((string) config('hosting.admin_email'));

        if ($email !== '' && ! User::where('email', $email)->exists()) {
            $password = (string) config('hosting.admin_password');

            if (strlen($password) < 8) {
                $this->error('ADMIN_PASSWORD must be at least 8 characters; the administrator was not created.');

                return self::FAILURE;
            }

            $user = User::create([
                'uuid'               => Str::uuid(),
                'firstName'          => (string) config('hosting.admin_first_name'),
                'lastName'           => (string) config('hosting.admin_last_name'),
                'email'              => $email,
                'emailVerifiedAt'    => now(),
                'password'           => Hash::make($password),
                'userType'           => 'librarian',
                'mustChangePassword' => true,
            ]);
            Librarian::create(['librarianID' => $user->userID, 'uuid' => Str::uuid(), 'role' => 'admin']);

            $this->info("Administrator {$email} created. The password must be changed at the first sign-in.");
        }

        return self::SUCCESS;
    }
}
