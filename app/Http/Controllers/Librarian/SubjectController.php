<?php

namespace App\Http\Controllers\Librarian;

use App\Http\Controllers\Controller;
use App\Models\BookSubject;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class SubjectController extends Controller
{
    public function index()
    {
        $subjects = BookSubject::withCount('books')->orderBy('name')->get();

        return response()->json(['subjects' => $subjects]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name'               => ['required', 'string', 'max:255', Rule::unique('book_subjects', 'name')],
            'classificationCode' => ['nullable', 'string', 'max:20', Rule::unique('book_subjects', 'classificationCode')],
        ]);

        $subject = BookSubject::create([
            'uuid'               => Str::uuid(),
            'name'               => $validated['name'],
            'classificationCode' => $validated['classificationCode'] ?? BookSubject::classifyByName($validated['name']),
        ]);

        return response()->json([
            'message' => 'Category added.',
            'subject' => $subject->loadCount('books'),
        ], 201);
    }

    public function update(Request $request, BookSubject $subject)
    {
        $validated = $request->validate([
            'name'               => ['sometimes', 'string', 'max:255', Rule::unique('book_subjects', 'name')->ignore($subject->subjectID, 'subjectID')],
            'classificationCode' => ['sometimes', 'nullable', 'string', 'max:20', Rule::unique('book_subjects', 'classificationCode')->ignore($subject->subjectID, 'subjectID')],
        ]);

        $subject->update($validated);

        return response()->json([
            'message' => 'Category updated.',
            'subject' => $subject->fresh()->loadCount('books'),
        ]);
    }

    public function destroy(BookSubject $subject)
    {
        // Includes soft-deleted books: the foreign key still points at this subject.
        if ($subject->books()->withTrashed()->exists()) {
            return response()->json([
                'message' => 'Cannot delete: books still use this category. Move or remove them first.',
            ], 422);
        }

        $subject->delete();

        return response()->json(['message' => 'Category removed.']);
    }
}
