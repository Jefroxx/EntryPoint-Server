<?php

namespace App\Http\Controllers\Librarian;

use App\Http\Controllers\Controller;
use App\Models\Penalty;
use App\Models\SystemNotification;
use Illuminate\Http\Request;

class PenaltyController extends Controller
{
    public function index(Request $request)
    {
        $validated = $request->validate([
            'search'  => ['nullable', 'string', 'max:255'],
            'status'  => ['nullable', 'string', 'in:Unpaid,Paid'],
            'perPage' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $penalties = Penalty::with(['loan.student.user', 'loan.copy.book', 'penaltyType'])
            ->when($validated['status'] ?? null, fn ($q, $status) => $q->where('paymentStatus', $status))
            ->when($validated['search'] ?? null, function ($q, $term) {
                $q->whereHas('loan.student.user', fn ($u) => $u
                    ->where('firstName', 'like', "%{$term}%")
                    ->orWhere('lastName', 'like', "%{$term}%"));
            })
            ->orderByDesc('computedAt')
            ->paginate($validated['perPage'] ?? 15);

        return response()->json($penalties);
    }

    public function stats()
    {
        return response()->json([
            'unpaidCount' => Penalty::where('paymentStatus', 'Unpaid')->count(),
            'unpaidTotal' => (float) Penalty::where('paymentStatus', 'Unpaid')->sum('amount'),
            'paidCount'   => Penalty::where('paymentStatus', 'Paid')->count(),
        ]);
    }

    public function settle(Penalty $penalty)
    {
        if ($penalty->paymentStatus === 'Paid') {
            return response()->json(['message' => 'This penalty is already settled.'], 422);
        }

        $penalty->load('loan.copy.book');

        // A penalty on a still-active loan keeps accruing, so its amount isn't final yet.
        if ($penalty->loan->status !== 'Returned') {
            return response()->json([
                'message' => 'This fine is still accruing. Return the book first, then settle the final amount.',
            ], 422);
        }

        $penalty->update([
            'paymentStatus' => 'Paid',
            'settledAt'     => now(),
        ]);

        $title = $penalty->loan->copy->book?->title ?? 'a library book';

        SystemNotification::notify(
            $penalty->loan->studentID,
            "Your fine of ₱{$penalty->amount} for \"{$title}\" has been settled.",
            'penalty_settled'
        );

        return response()->json([
            'message' => 'Penalty settled.',
            'penalty' => $penalty->fresh()->load(['loan.student.user', 'loan.copy.book', 'penaltyType']),
        ]);
    }
}
