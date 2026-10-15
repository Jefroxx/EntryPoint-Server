<?php

namespace App\Http\Controllers\Librarian;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreLoanRequest;
use App\Models\Loan;
use App\Services\CirculationService;
use Illuminate\Http\Request;

class LoanController extends Controller
{
    public function __construct(private CirculationService $circulation)
    {
    }

    public function index(Request $request)
    {
        return response()->json($this->circulation->index(
            $request->query('search'),
            $request->query('status'),
            (int) ($request->query('perPage') ?? 15)
        ));
    }

    public function stats()
    {
        return response()->json($this->circulation->stats());
    }

    public function store(StoreLoanRequest $request)
    {
        $loan = $this->circulation->checkout(
            $request->validated(),
            $request->user()->librarian->librarianID
        );

        return response()->json([
            'message' => 'Book checked out successfully.',
            'loan'    => $loan,
            'receipt' => $this->circulation->receipt($loan, $request->user()->fullName),
        ], 201);
    }

    /** The checkout receipt again, for a reprint. */
    public function receipt(Request $request, Loan $loan)
    {
        return response()->json(['receipt' => $this->circulation->receipt($loan, $request->user()->fullName)]);
    }

    /**
     * Librarian-assisted return — the student hands the physical book
     * back at the counter and staff processes it directly (as opposed
     * to Student\LoanController::selfReturn(), which stages a report
     * for later librarian verification).
     */
    /** The student handed the book over: it is now in the librarian's hands, pending a check for damage. */
    public function receive(Loan $loan)
    {
        $loan = $this->circulation->receiveBook($loan);

        return response()->json([
            'message' => 'Book received. Check it for damage to finish the return.',
            'loan'    => $loan,
        ]);
    }

    /** After checking the received book: back on the shelf if it is fine, marked damaged if it isn't. */
    public function finishReturn(Request $request, Loan $loan)
    {
        $data = $request->validate([
            'condition' => ['required', 'in:good,damaged'],
            'note'      => ['nullable', 'string', 'max:500'],
        ]);

        $loan = $this->circulation->finishReturn($loan, $data['condition'], $data['note'] ?? null);

        return response()->json([
            'message' => $data['condition'] === 'damaged' ? 'Return finished. The copy is marked damaged.' : 'Return finished. The copy is back on the shelf.',
            'loan'    => $loan,
        ]);
    }

    /** The "Return this book?" window: the loan, book and student, and the fine returning it now would charge. */
    public function returnPreview(Loan $loan)
    {
        return response()->json(['preview' => $this->circulation->returnPreview($loan)]);
    }

    /** Returns the book. `finePaid` says the student paid the late fee at the desk, so the fine is settled with it. */
    public function returnBook(Request $request, Loan $loan)
    {
        $data = $request->validate([
            'finePaid'      => ['sometimes', 'boolean'],
            // The cashier's receipt number is required whenever the late fee is collected with the return.
            'receiptNumber' => ['required_if:finePaid,true,1', 'nullable', 'string', 'max:50'],
        ]);

        $loan = $this->circulation->returnBook($loan, (bool) ($data['finePaid'] ?? false), $data['receiptNumber'] ?? null);
        $penalty = $loan->penalties()->latest('computedAt')->first();

        return response()->json([
            'message' => match (true) {
                ! $penalty                         => 'Book returned successfully.',
                $penalty->paymentStatus === 'Paid' => 'Book returned. The late fee was marked as paid.',
                default                            => 'Book returned. The late fee is still unpaid.',
            },
            'loan'    => $loan,
            'penalty' => $penalty?->only(['amount', 'paymentStatus']),
        ]);
    }
}
