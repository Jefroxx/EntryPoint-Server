<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SaveAnnouncementRequest;
use App\Models\Announcement;
use App\Services\AnnouncementService;
use Illuminate\Http\Request;

class AnnouncementController extends Controller
{
    public function __construct(private AnnouncementService $announcements)
    {
    }

    public function index()
    {
        return response()->json(['announcements' => $this->announcements->all()]);
    }

    public function store(SaveAnnouncementRequest $request)
    {
        return response()->json(['announcement' => $this->announcements->create($request->user(), $request->validated())], 201);
    }

    public function update(SaveAnnouncementRequest $request, Announcement $announcement)
    {
        return response()->json(['announcement' => $this->announcements->update($request->user(), $announcement, $request->validated())]);
    }

    public function destroy(Request $request, Announcement $announcement)
    {
        $this->announcements->delete($request->user(), $announcement);

        return response()->json(['message' => 'Announcement removed.']);
    }
}
