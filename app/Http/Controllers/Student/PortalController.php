<?php

namespace App\Http\Controllers\Student;

use App\Http\Controllers\Controller;
use App\Http\Requests\StudentCatalogRequest;
use App\Models\Book;
use App\Services\HallOfFameService;
use App\Services\StudentPortalService;
use Illuminate\Http\Request;

class PortalController extends Controller
{
    public function __construct(private StudentPortalService $portalService)
    {
    }

    public function profile(Request $request)
    {
        return response()->json($this->portalService->profile($request->user()->student));
    }

    public function hallOfFame(Request $request, HallOfFameService $hallOfFame)
    {
        return response()->json($hallOfFame->board($request->user()->student->studentID));
    }

    public function loans(Request $request)
    {
        return response()->json(['loans' => $this->portalService->loans($request->user()->student)]);
    }

    /** How the student will settle a lost or damaged book: pay it, or replace it. */
    public function choosePenalty(Request $request, \App\Models\Penalty $penalty, \App\Services\CopyPenaltyService $copyPenalties)
    {
        $data = $request->validate(['choice' => ['required', 'in:pay,replace']]);

        $copyPenalties->chooseResolution($penalty, $request->user()->student->studentID, $data['choice']);

        return response()->json(['message' => $data['choice'] === 'replace'
            ? 'Noted. Bring a replacement copy to the library desk.'
            : 'Noted. You can pay it at the library desk.']);
    }

    public function penalties(Request $request)
    {
        return response()->json($this->portalService->penalties($request->user()->student));
    }

    public function attendance(Request $request)
    {
        return response()->json($this->portalService->attendance($request->user()->student));
    }

    public function redemptions(Request $request)
    {
        return response()->json(['redemptions' => $this->portalService->redemptions($request->user()->student)]);
    }

    public function catalog(StudentCatalogRequest $request)
    {
        return response()->json($this->portalService->catalog($request->validated()));
    }

    public function recommendations(Request $request)
    {
        return response()->json($this->portalService->recommendations($request->user()->student));
    }

    public function catalogShow(Book $book)
    {
        return response()->json(['book' => $this->portalService->catalogBook($book)]);
    }

    public function subjects()
    {
        return response()->json(['subjects' => $this->portalService->subjects()]);
    }

    /** The areas of the library with books in them: what Discover and Home let students browse by. */
    public function areas()
    {
        return response()->json(['areas' => $this->portalService->areas()]);
    }
}
