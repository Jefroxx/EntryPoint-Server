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
        private AchievementService $achievements,
    ) {
    }

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

        // An approved suggestion counts toward achievements.
        $this->achievements->evaluateForStudent($suggestion->student);

        $this->notifications->send(
            $suggestion->studentID,
            "Good news! Your suggestion for \"{$suggestion->title}\" has been approved and will be added to the collection.",
            'book_suggestion_approved'
        );

        return $suggestion->fresh();
    }

    /** The approved book is now in the library: the student who asked for it is told. */
    public function markArrived(BookSuggestion $suggestion, int $librarianID): BookSuggestion
    {
        if ($suggestion->status !== 'Approved') {
            throw ValidationException::withMessages([
                'suggestion' => ["Only an 'Approved' request can be marked as arrived."],
            ]);
        }

        $this->suggestions->update($suggestion, [
            'status'                => 'Arrived',
            'progressStep'          => 'Arrived',
            'reviewedByLibrarianID' => $librarianID,
        ]);

        $this->notifications->send(
            $suggestion->studentID,
            "Good news! \"{$suggestion->title}\", the book you requested, has arrived in the library. You can borrow it now.",
            'book_suggestion_arrived'
        );

        return $suggestion->fresh();
    }

    public function reject(BookSuggestion $suggestion, int $librarianID, ?string $reason = null): BookSuggestion
    {
        if ($suggestion->status !== 'Pending') {
            throw ValidationException::withMessages([
                'suggestion' => ["Only a 'Pending' suggestion can be rejected."],
            ]);
        }

        $this->suggestions->update($suggestion, [
            'status'                => 'Rejected',
            'progressStep'          => 'Rejected',
            'rejectionReason'       => $reason !== null && trim($reason) !== '' ? trim($reason) : null,
            'reviewedByLibrarianID' => $librarianID,
        ]);

        $this->notifications->send(
            $suggestion->studentID,
            "Your suggestion for \"{$suggestion->title}\" was not approved this time." . ($reason !== null && trim($reason) !== '' ? ' Reason: ' . trim($reason) : ''),
            'book_suggestion_rejected'
        );

        return $suggestion->fresh();
    }
}
