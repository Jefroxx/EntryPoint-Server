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
        'password',
        'phoneNumber',
        'birthDate',
        'address',
        'userType',
    ];

    protected $hidden = [
        'password',
    ];

    protected $casts = [
        'birthDate' => 'date',
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

    public function getFullNameAttribute(): string
    {
        $middle = $this->middleInitial ? " {$this->middleInitial}." : '';
        return "{$this->firstName}{$middle} {$this->lastName}";
    }
}
