<?php

namespace App\Http\Controllers\Librarian;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreSubjectRequest;
use App\Http\Requests\UpdateSubjectRequest;
use App\Models\BookSubject;
use App\Services\SubjectService;

class SubjectController extends Controller
{
    public function __construct(private SubjectService $subjectService)
    {
    }

    public function index()
    {
        return response()->json(['subjects' => $this->subjectService->list()]);
    }

    public function store(StoreSubjectRequest $request)
    {
        $subject = $this->subjectService->create($request->validated());

        return response()->json([
            'message' => 'Subject added.',
            'subject' => $subject,
        ], 201);
    }

    public function update(UpdateSubjectRequest $request, BookSubject $subject)
    {
        $subject = $this->subjectService->update($subject, $request->validated());

        return response()->json([
            'message' => 'Subject updated.',
            'subject' => $subject,
        ]);
    }

    public function destroy(BookSubject $subject)
    {
        $this->subjectService->delete($subject);

        return response()->json(['message' => 'Subject removed.']);
    }
}
