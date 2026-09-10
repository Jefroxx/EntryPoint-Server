<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

// Named SystemNotification (not Notification) to avoid clashing with
// Laravel's built-in Illuminate\Notifications\Notification class.
class SystemNotification extends Model
{
    use HasFactory;

    protected $table = 'notifications';
    protected $primaryKey = 'notificationID';

    protected $fillable = ['uuid', 'userID', 'message', 'type', 'sentAt', 'isRead'];

    protected $casts = [
        'sentAt' => 'datetime',
        'isRead' => 'boolean',
    ];

    public function user()
    {
        return $this->belongsTo(User::class, 'userID', 'userID');
    }

    public static function notify(int $userID, string $message, ?string $type = null): self
    {
        return self::create([
            'uuid'    => \Illuminate\Support\Str::uuid(),
            'userID'  => $userID,
            'message' => $message,
            'type'    => $type,
            'sentAt'  => now(),
            'isRead'  => false,
        ]);
    }
}
