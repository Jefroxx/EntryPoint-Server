<?php

namespace App\Repositories\Contracts;

use App\Models\Reservation;
use Illuminate\Database\Eloquent\Collection;

interface ReservationRepositoryInterface extends RepositoryInterface
{
    public function listWithFilters(?string $status): Collection;

    public function queueForBook(int $bookID): Collection;

    public function forStudent(int $studentID): Collection;

    public function activeCountForStudent(int $studentID, bool $lock = false): int;

    public function alreadyReservedForBooks(int $studentID, array $bookIDs): bool;

    public function earliestWaitingForBook(int $bookID): ?Reservation;

    public function queuePositionAheadOf(int $bookID, \DateTimeInterface $reservedAt): int;

    public function readyCountForStudent(int $studentID): int;

    /** The student's own Accepted reservation for this book, if any (the earliest one). */
    public function acceptedForStudentAndBook(int $studentID, int $bookID): ?Reservation;

    /** Accepted reservations for a book: each one holds a copy until it is collected. */
    public function acceptedCountForBook(int $bookID): int;

    /**
     * Number of Waiting reservations per book, keyed by bookID.
     *
     * @param  array<int,int>  $bookIDs
     * @return array<int,int>
     */
    public function waitingCountsByBook(array $bookIDs): array;
}
