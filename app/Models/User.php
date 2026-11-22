<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    protected $primaryKey = 'userID';

    protected $fillable = [
        'uuid',
        'firstName',
        'middleInitial',
        'lastName',
        'email',
        'username',
        'emailVerifiedAt',
        'password',
        'phoneNumber',
        'birthDate',
        'address',
        'userType',
        'disabledAt',
        'mustChangePassword',
    ];

    protected $hidden = [
        'password',
    ];

    protected $casts = [
        'birthDate'       => 'date',
        'emailVerifiedAt' => 'datetime',
        'disabledAt'      => 'datetime',
        'mustChangePassword' => 'boolean',
    ];

    public function student()
    {
        return $this->hasOne(Student::class, 'studentID', 'userID');
    }

    public function librarian()
    {
        return $this->hasOne(Librarian::class, 'librarianID', 'userID');
    }

    public function notifications()
    {
        return $this->hasMany(SystemNotification::class, 'userID', 'userID');
    }

    /**
     * Laravel's password reset calls this with the one-time token. The link goes to the site the person is on (like the
     * confirmation email's), where /reset-password asks for the new password.
     */
    public function sendPasswordResetNotification($token): void
    {
        $url = \App\Support\PortalUrl::current() . '/reset-password?' . http_build_query(['token' => $token, 'email' => $this->email]);

        \Illuminate\Support\Facades\Mail::to($this->email)->send(new \App\Mail\ResetPasswordMail($this, $url));
    }

    /** An account the administrator has switched off: it can't sign in and its tokens are revoked. */
    public function isDisabled(): bool
    {
        return $this->disabledAt !== null;
    }

    protected static function booted(): void
    {
        // An emailed "choose a new password" link is only good while the password it was asked for is still the
        // password. However it changes (the link itself, Profile, an administrator's reset, the first-sign-in change),
        // every link still waiting is cancelled. A changed email cancels the links sent to the old address too.
        static::updated(function (User $user) {
            if ($user->wasChanged('password')) {
                \Illuminate\Support\Facades\DB::table(config('auth.passwords.users.table'))->where('email', $user->email)->delete();
            }
            if ($user->wasChanged('email')) {
                \Illuminate\Support\Facades\DB::table(config('auth.passwords.users.table'))->where('email', $user->getOriginal('email'))->delete();
            }
        });
    }

    public function getFullNameAttribute(): string
    {
        // The dot is added here, so one typed with the letter ("U.") must not become "U..".
        $initial = rtrim(trim((string) $this->middleInitial), '. ');
        $middle = $initial !== '' ? " {$initial}." : '';
        return "{$this->firstName}{$middle} {$this->lastName}";
    }
}
