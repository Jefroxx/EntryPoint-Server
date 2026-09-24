<?php

namespace App\Repositories\Contracts;

use App\Models\Student;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;

interface StudentRepositoryInterface extends RepositoryInterface
{
    public function paginate(?string $search, ?string $program, ?string $status, int $perPage): LengthAwarePaginator;

    /**
     * @return array{total: int, pending: int, approved: int, rejected: int}
     */
    public function countsByRegistrationStatus(): array;

    /**
     * Adjusts a student's knowledgeScore by $delta (positive to credit,
     * negative to deduct) and returns the same instance with the new value
     * already reflected, so callers chaining further reads see it live.
     */
    public function creditPoints(Student $student, int $delta): Student;

    public function findByBarcode(string $barcode): ?Student;

    /**
     * Looks a student up by barcode or by student ID number (the typed fallback at the scan
     * station). $lock takes a row lock, for use inside a transaction.
     */
    public function findForScan(string $code, bool $lock = false): ?Student;

    public function notHavingAchievement(int $achievementID): Collection;

    public function approvedCount(): int;

    /** Pending registrations whose email is confirmed, i.e. the ones a librarian can approve now. */
    public function readyForReviewCount(): int;

    /** Approved members per academic program (program => count). */
    public function approvedCountByProgram(): \Illuminate\Support\Collection;

    public function generateUniqueBarcode(): string;
}
