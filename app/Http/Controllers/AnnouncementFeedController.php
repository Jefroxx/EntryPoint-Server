<?php

namespace App\Http\Controllers;

use App\Services\AnnouncementService;

/** What each side reads: the active announcements meant for it. */
class AnnouncementFeedController extends Controller
{
    public function __construct(private AnnouncementService $announcements)
    {
    }

    public function student()
    {
        return response()->json(['announcements' => $this->announcements->forSide('students')]);
    }

    public function staff()
    {
        return response()->json(['announcements' => $this->announcements->forSide('staff')]);
    }
}
