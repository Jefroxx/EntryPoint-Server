<?php

namespace App\Services;

use App\Models\BookSuggestion;
use App\Models\Student;
use App\Repositories\Contracts\BookSuggestionRepositoryInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class BookSuggestionService
{
    public function __construct(
        private BookSuggestionRepositoryInterface $suggestions,
        private NotificationService $notifications,
    ) {}

    public function listForLibrarian(?string $status): Collection
    {
        return $this->suggestions->listWithFilters($status);
    }

    public function listForStudent(int $studentID): Collection
    {
        return $this->suggestions->forStudent($studentID);
    }

    public function submit(Student $student, array $validated): BookSuggestion
    {
        $suggestion = $this->suggestions->create([
            'uuid'         => Str::uuid(),
            'studentID'    => $student->studentID,
            'title'        => $validated['title'],
            'author'       => $validated['author'] ?? null,
            'reason'       => $validated['reason'] ?? null,
            'status'       => 'Pending',
            'progressStep' => 'Submitted',
            'submittedAt'  => now(),
        ]);

        $this->notifications->notifyAllLibrarians(
            "{$student->user->fullName} suggested a new book: \"{$suggestion->title}\".",
            'new_book_suggestion'
        );

        return $suggestion;
    }

    public function approve(BookSuggestion $suggestion, int $librarianID): BookSuggestion
    {
        if ($suggestion->status !== 'Pending') {
            throw ValidationException::withMessages([
                'suggestion' => ["Only a 'Pending' suggestion can be approved."],
            ]);
        }

        $this->suggestions->update($suggestion, [
            'status'                => 'Approved',
            'progressStep'          => 'Approved',
            'reviewedByLibrarianID' => $librarianID,
        ]);

        $this->notifications->send(
            $suggestion->studentID,
            "Good news! Your suggestion for \"{$suggestion->title}\" has been approved and will be added to the collection.",
            'book_suggestion_approved'
        );

        return $suggestion->fresh();
    }

    public function reject(BookSuggestion $suggestion, int $librarianID): BookSuggestion
    {
        if ($suggestion->status !== 'Pending') {
            throw ValidationException::withMessages([
                'suggestion' => ["Only a 'Pending' suggestion can be rejected."],
            ]);
        }

        $this->suggestions->update($suggestion, [
            'status'                => 'Rejected',
            'progressStep'          => 'Rejected',
            'reviewedByLibrarianID' => $librarianID,
        ]);

        $this->notifications->send(
            $suggestion->studentID,
            "Your suggestion for \"{$suggestion->title}\" was not approved this time.",
            'book_suggestion_rejected'
        );

        return $suggestion->fresh();
    }
}
