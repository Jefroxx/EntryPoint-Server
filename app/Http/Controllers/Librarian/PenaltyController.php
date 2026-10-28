<?php

namespace App\Http\Controllers\Librarian;

use App\Http\Controllers\Controller;
use App\Models\BookCopy;
use App\Models\Loan;
use App\Models\Penalty;
use App\Services\CirculationService;
use App\Services\CopyPenaltyService;
use Illuminate\Http\Request;

class PenaltyController extends Controller
{
    public function __construct(private CirculationService $circulation, private CopyPenaltyService $copyPenalties)
    {
    }

    /**
     * Charges a student for a lost or damaged book. Either `loan` (the loan the book was lost on) or `studentID` and
     * `copyID` (a book lost or damaged inside the library, with no loan). The copy is marked lost or damaged too.
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'type'      => ['required', 'in:lost,damaged'],
            'amount'    => ['required', 'numeric', 'gt:0', 'max:99999999.99'],
            'loan'      => ['nullable', 'uuid', 'exists:loans,uuid'],
            'studentID' => ['required_without:loan', 'nullable', 'integer', 'exists:students,studentID'],
            'copyID'    => ['required_without:loan', 'nullable', 'integer', 'exists:book_copies,copyID'],
        ]);

        $loan = isset($data['loan']) ? Loan::where('uuid', $data['loan'])->with('copy')->firstOrFail() : null;
        $copy = ! $loan && isset($data['copyID']) ? BookCopy::findOrFail($data['copyID']) : null;

        $penalty = $this->copyPenalties->issue($data['type'], (float) $data['amount'], $loan, $data['studentID'] ?? null, $copy);

        return response()->json(['message' => 'Penalty issued.', 'penalty' => $penalty], 201);
    }

    /** The book turned up or was repaired: the penalty is cancelled and the copy goes back on the shelf. */
    public function cancel(Penalty $penalty)
    {
        $this->copyPenalties->cancel($penalty);

        return response()->json(['message' => 'Penalty cancelled.']);
    }

    /** The student replaced the book instead of paying: the penalty closes and the new copy joins the catalog. */
    public function replace(Penalty $penalty)
    {
        $result = $this->copyPenalties->replace($penalty);

        return response()->json([
            'message'     => 'Replacement received.',
            'penalty'     => $result['penalty'],
            'replacement' => ['uuid' => $result['replacement']->uuid, 'accessionNumber' => $result['replacement']->accessionNumber],
        ]);
    }

    public function index(Request $request)
    {
        $filters = $request->validate([
            'search'  => ['nullable', 'string', 'max:255'],
            'status'  => ['nullable', 'in:Unpaid,Paid,Replaced,longUnpaid'],
            'perPage' => ['nullable', 'integer', 'min:1', 'max:100'],
            // 'copy': a lost or damaged book (the Lost & Damaged tab); 'late': late fees (the Penalties tab).
            'kind'    => ['nullable', 'in:copy,late'],
        ]);

        return response()->json($this->circulation->penaltiesIndex(
            $filters['search'] ?? null,
            $filters['status'] ?? null,
            (int) ($filters['perPage'] ?? 15),
            $filters['kind'] ?? null
        ));
    }

    public function stats(Request $request)
    {
        $filters = $request->validate(['kind' => ['nullable', 'in:copy,late']]);

        return response()->json($this->circulation->penaltyStats($filters['kind'] ?? null));
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
