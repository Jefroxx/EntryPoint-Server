<?php

namespace App\Http\Controllers\Librarian;

use App\Http\Controllers\Controller;
use App\Services\ReportService;
use Illuminate\Http\Request;

class ReportController extends Controller
{
    public function __construct(private ReportService $reports)
    {
    }

    public function overview(Request $request)
    {
        $months = (int) ($request->query('months') ?? 3);
        $months = in_array($months, [3, 6, 12], true) ? $months : 3;

        return response()->json($this->reports->overview($months));
    }
}
