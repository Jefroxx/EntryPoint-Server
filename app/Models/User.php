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

    /** An account the administrator has switched off: it can't sign in and its tokens are revoked. */
    public function isDisabled(): bool
    {
        return $this->disabledAt !== null;
    }

    public function getFullNameAttribute(): string
    {
        $middle = $this->middleInitial ? " {$this->middleInitial}." : '';
        return "{$this->firstName}{$middle} {$this->lastName}";
    }
}
