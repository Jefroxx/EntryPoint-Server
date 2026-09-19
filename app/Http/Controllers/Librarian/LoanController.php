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
        ], 201);
    }

    /**
     * Librarian-assisted return — the student hands the physical book
     * back at the counter and staff processes it directly (as opposed
     * to Student\LoanController::selfReturn(), which stages a report
     * for later librarian verification).
     */
    public function returnBook(Loan $loan)
    {
        $loan = $this->circulation->returnBook($loan);

        return response()->json([
            'message' => 'Book returned successfully.',
            'loan'    => $loan,
        ]);
    }
}
