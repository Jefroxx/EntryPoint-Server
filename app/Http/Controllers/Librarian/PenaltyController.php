<?php

namespace App\Http\Controllers\Librarian;

use App\Http\Controllers\Controller;
use App\Models\Penalty;
use App\Services\CirculationService;
use Illuminate\Http\Request;

class PenaltyController extends Controller
{
    public function __construct(private CirculationService $circulation)
    {
    }

    public function index(Request $request)
    {
        return response()->json($this->circulation->penaltiesIndex(
            $request->query('search'),
            $request->query('status'),
            (int) ($request->query('perPage') ?? 15)
        ));
    }

    public function stats()
    {
        return response()->json($this->circulation->penaltyStats());
    }

    public function settle(Request $request, Penalty $penalty)
    {
        $data = $request->validate(['receiptNumber' => ['required', 'string', 'max:50']]);

        $penalty = $this->circulation->settlePenalty($penalty, $data['receiptNumber']);

        return response()->json([
            'message' => 'Penalty settled.',
            'penalty' => $penalty,
        ]);
    }
}
