<?php

namespace App\Http\Controllers\Student;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreBookSuggestionRequest;
use App\Services\BookSuggestionService;
use Illuminate\Http\Request;

class BookSuggestionController extends Controller
{
    public function __construct(private BookSuggestionService $bookSuggestions)
    {
    }

    public function index(Request $request)
    {
        $student = $request->user()->student;

        return response()->json([
            'suggestions' => $this->bookSuggestions->listForStudent($student->studentID),
        ]);
    }

    public function store(StoreBookSuggestionRequest $request)
    {
        $suggestion = $this->bookSuggestions->submit($request->user()->student, $request->validated());

        return response()->json([
            'message'    => 'Suggestion submitted. A librarian will review it shortly.',
            'suggestion' => $suggestion,
        ], 201);
    }
}
