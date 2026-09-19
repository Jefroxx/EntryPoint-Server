<?php

namespace App\Http\Controllers\Student;

use App\Http\Controllers\Controller;
use App\Http\Requests\StudentCatalogRequest;
use App\Models\Book;
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

    public function loans(Request $request)
    {
        return response()->json(['loans' => $this->portalService->loans($request->user()->student)]);
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

    public function catalogShow(Book $book)
    {
        return response()->json(['book' => $this->portalService->catalogBook($book)]);
    }

    public function subjects()
    {
        return response()->json(['subjects' => $this->portalService->subjects()]);
    }
}
