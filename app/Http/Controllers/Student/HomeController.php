<?php

namespace App\Http\Controllers\Student;

use App\Http\Controllers\Controller;
use App\Services\AchievementService;
use App\Services\AnnouncementService;
use App\Services\HallOfFameService;
use App\Services\ReservationService;
use App\Services\StudentPortalService;
use Illuminate\Http\Request;

/**
 * Everything the student Home screen shows, in one request. Home used to make nine requests of its own, and on the
 * hosted API (a small share of one CPU) each request pays for starting Laravel again, so nine at once queued for the
 * processor. Each part is built by the same service as its own endpoint, so the shapes match what those endpoints
 * return (they stay for the other screens).
 */
class HomeController extends Controller
{
    public function __invoke(
        Request $request,
        StudentPortalService $portal,
        ReservationService $reservations,
        AchievementService $achievements,
        AnnouncementService $announcements,
        HallOfFameService $hallOfFame,
    ) {
        $student = $request->user()->student;
        // Points, achievements and the Hall of Fame are for students only (the `engagement` middleware on their own routes).
        $engaged = $student->takesPartInEngagement();

        try {
            $picks = $portal->recommendations($student);
        } catch (\Throwable $e) {
            // A failed ranking just means an empty "Recommended" card, never a broken Home (as before).
            report($e);
            $picks = ['books' => [], 'subjects' => [], 'classes' => [], 'loanCount' => 0];
        }

        return response()->json([
            'loans'           => $portal->loans($student),
            'reservations'    => $reservations->indexForStudent($student->studentID),
            'attendance'      => $portal->attendance($student),
            'achievements'    => $engaged ? $achievements->indexForStudent($student) : [],
            // Home's "Browse by area" row (it used to list subjects, which new books no longer have).
            'areas'           => $portal->areas(),
            'recommendations' => $picks,
            'announcements'   => $announcements->forSide('students'),
            'hallOfFame'      => $engaged ? $hallOfFame->board($student->studentID) : null,
            'newArrivals'     => $portal->catalog(['newArrivalsOnly' => true, 'perPage' => 10]),
        ]);
    }
}
