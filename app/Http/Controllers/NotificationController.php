<?php

namespace App\Http\Controllers;

use App\Models\SystemNotification;
use App\Services\NotificationService;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    public function __construct(private NotificationService $notifications)
    {
    }

    public function index(Request $request)
    {
        return response()->json($this->notifications->listForUser($request->user()->userID));
    }

    public function markAsRead(Request $request, SystemNotification $notification)
    {
        $this->notifications->markAsRead($notification, $request->user()->userID);

        return response()->json(['message' => 'Marked as read.']);
    }

    public function markAllAsRead(Request $request)
    {
        $this->notifications->markAllAsRead($request->user()->userID);

        return response()->json(['message' => 'All notifications marked as read.']);
    }
}
