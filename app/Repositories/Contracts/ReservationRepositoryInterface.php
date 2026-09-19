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
}
