<?php

namespace App\Http\Controllers\Librarian;

use App\Http\Controllers\Controller;
use App\Models\BookSuggestion;
use App\Services\BookSuggestionService;
use Illuminate\Http\Request;

class BookSuggestionController extends Controller
{
    public function __construct(private BookSuggestionService $bookSuggestions)
    {
    }

    public function index(Request $request)
    {
        return response()->json([
            'suggestions' => $this->bookSuggestions->listForLibrarian($request->query('status')),
        ]);
    }

    public function approve(Request $request, BookSuggestion $suggestion)
    {
        $suggestion = $this->bookSuggestions->approve($suggestion, $request->user()->librarian->librarianID);

        return response()->json([
            'message'    => 'Suggestion approved.',
            'suggestion' => $suggestion,
        ]);
    }

    public function reject(Request $request, BookSuggestion $suggestion)
    {
        $suggestion = $this->bookSuggestions->reject($suggestion, $request->user()->librarian->librarianID);

        return response()->json([
            'message'    => 'Suggestion rejected.',
            'suggestion' => $suggestion,
        ]);
    }
}
