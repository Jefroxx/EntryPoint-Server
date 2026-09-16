<?php

namespace App\Http\Controllers\Student;

use App\Http\Controllers\Controller;
use App\Models\BookSuggestion;
use App\Models\Librarian;
use App\Models\SystemNotification;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class BookSuggestionController extends Controller
{
    public function index(Request $request)
    {
        $student = $request->user()->student;

        $suggestions = $student->bookSuggestions()->orderByDesc('submittedAt')->get();

        return response()->json(['suggestions' => $suggestions]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'title'  => ['required', 'string', 'max:255'],
            'author' => ['nullable', 'string', 'max:255'],
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);

        $student = $request->user()->student;

        $suggestion = BookSuggestion::create([
            'uuid'         => Str::uuid(),
            'studentID'    => $student->studentID,
            'title'        => $validated['title'],
            'author'       => $validated['author'] ?? null,
            'reason'       => $validated['reason'] ?? null,
            'status'       => 'Pending',
            'progressStep' => 'Submitted',
            'submittedAt'  => now(),
        ]);

        Librarian::all()->each(function ($librarian) use ($student, $suggestion) {
            SystemNotification::notify(
                $librarian->librarianID,
                "{$student->user->fullName} suggested a new book: \"{$suggestion->title}\".",
                'new_book_suggestion'
            );
        });

        return response()->json([
            'message'    => 'Suggestion submitted. A librarian will review it shortly.',
            'suggestion' => $suggestion,
        ], 201);
    }
}
