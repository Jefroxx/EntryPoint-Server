<?php

namespace App\Http\Controllers\Librarian;

use App\Http\Controllers\Controller;
use App\Models\BookSuggestion;
use App\Models\SystemNotification;
use Illuminate\Http\Request;

class BookSuggestionController extends Controller
{
    public function index(Request $request)
    {
        $query = BookSuggestion::with('student.user')->orderByDesc('submittedAt');

        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        }

        return response()->json(['suggestions' => $query->get()]);
    }

    public function approve(Request $request, BookSuggestion $suggestion)
    {
        if ($suggestion->status !== 'Pending') {
            return response()->json([
                'message' => "Only a 'Pending' suggestion can be approved.",
            ], 422);
        }

        $suggestion->update([
            'status'                => 'Approved',
            'progressStep'          => 'Approved',
            'reviewedByLibrarianID' => $request->user()->librarian->librarianID,
        ]);

        SystemNotification::notify(
            $suggestion->studentID,
            "Good news! Your suggestion for \"{$suggestion->title}\" has been approved and will be added to the collection.",
            'book_suggestion_approved'
        );

        return response()->json([
            'message'    => 'Suggestion approved.',
            'suggestion' => $suggestion->fresh(),
        ]);
    }

    public function reject(Request $request, BookSuggestion $suggestion)
    {
        if ($suggestion->status !== 'Pending') {
            return response()->json([
                'message' => "Only a 'Pending' suggestion can be rejected.",
            ], 422);
        }

        $suggestion->update([
            'status'                => 'Rejected',
            'progressStep'          => 'Rejected',
            'reviewedByLibrarianID' => $request->user()->librarian->librarianID,
        ]);

        SystemNotification::notify(
            $suggestion->studentID,
            "Your suggestion for \"{$suggestion->title}\" was not approved this time.",
            'book_suggestion_rejected'
        );

        return response()->json([
            'message'    => 'Suggestion rejected.',
            'suggestion' => $suggestion->fresh(),
        ]);
    }
}
