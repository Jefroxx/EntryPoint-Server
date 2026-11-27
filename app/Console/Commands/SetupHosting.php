<?php

namespace App\Console\Commands;

use App\Models\Librarian;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Runs when a hosted copy starts (see docker/entrypoint.sh). A host without a shell cannot run "db:seed" by hand, so
 * the two first-time jobs are driven by settings instead:
 *   - SEED_DEMO=true     fills an EMPTY database with the sample data (accounts with a known password: demos only).
 *   - ADMIN_EMAIL        creates that administrator if it does not exist yet, using ADMIN_PASSWORD, and makes the
 *                        account change the password at the first sign-in.
 * Both are safe to leave on: they do nothing once the data is there.
 *   - RESET_DEMO_DATA    a word of your choice (say "2026-11-27"). The FIRST start that sees a new word wipes the whole
 *                        database and loads the demo data; the word is then remembered in the database, so later
 *                        restarts leave the data alone. To reset again some day, set a different word.
 */
class SetupHosting extends Command
{
    protected $signature = 'entrypoint:setup';

    protected $description = 'First-time setup of a hosted copy: optional sample data and the first administrator.';

    /** Where the last reset's word is kept (app_settings), so one word wipes the database once and only once. */
    private const RESET_KEY = 'demo_data_reset';

    public function handle(): int
    {
        $reset = trim((string) config('hosting.reset_demo'));
        if ($reset !== '' && DB::table('app_settings')->where('key', self::RESET_KEY)->value('value') !== $reset) {
            $this->warn("RESET_DEMO_DATA is \"{$reset}\", a new word: wiping the database and loading the demo data…");
            Artisan::call('migrate:fresh', ['--force' => true, '--seed' => true]);
            $this->line(trim(Artisan::output()));
            DB::table('app_settings')->updateOrInsert(['key' => self::RESET_KEY], ['value' => $reset]);
            $this->info('Demo data loaded. This word will not reset the database again.');
        }

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
