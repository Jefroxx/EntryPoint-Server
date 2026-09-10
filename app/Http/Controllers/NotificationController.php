<?php

namespace App\Http\Controllers;

use App\Models\SystemNotification;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    public function index(Request $request)
    {
        $userID = $request->user()->userID;

        $notifications = SystemNotification::where('userID', $userID)
            ->orderByDesc('sentAt')
            ->get();

        return response()->json([
            'notifications' => $notifications,
            'unreadCount'   => $notifications->where('isRead', false)->count(),
        ]);
    }

    public function markAsRead(Request $request, SystemNotification $notification)
    {
        if ($notification->userID !== $request->user()->userID) {
            return response()->json(['message' => 'This notification does not belong to you.'], 403);
        }

        $notification->update(['isRead' => true]);

        return response()->json(['message' => 'Marked as read.']);
    }

    public function markAllAsRead(Request $request)
    {
        SystemNotification::where('userID', $request->user()->userID)
            ->where('isRead', false)
            ->update(['isRead' => true]);

        return response()->json(['message' => 'All notifications marked as read.']);
    }
}
