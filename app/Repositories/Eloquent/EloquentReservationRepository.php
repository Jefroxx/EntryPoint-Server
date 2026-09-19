<?php

namespace App\Repositories\Eloquent;

use App\Models\Reservation;
use App\Repositories\Contracts\ReservationRepositoryInterface;
use Illuminate\Database\Eloquent\Collection;

class EloquentReservationRepository extends BaseRepository implements ReservationRepositoryInterface
{
    public function __construct(Reservation $model)
    {
        parent::__construct($model);
    }

    public function listWithFilters(?string $status): Collection
    {
        return Reservation::with(['student.user', 'book'])
            ->when($status, fn ($query) => $query->where('status', $status))
            ->orderBy('reservedAt')
            ->get();
    }

    public function queueForBook(int $bookID): Collection
    {
        return Reservation::where('bookID', $bookID)
            ->where('status', 'Waiting')
            ->with(['student.user', 'book'])
            ->orderBy('reservedAt')
            ->get();
    }

    public function forStudent(int $studentID): Collection
    {
        return Reservation::where('studentID', $studentID)
            ->with('book.subject', 'book.authors')
            ->orderByDesc('reservedAt')
            ->get();
    }

    public function activeCountForStudent(int $studentID, bool $lock = false): int
    {
        $query = Reservation::where('studentID', $studentID)
            ->whereIn('status', ['Waiting', 'Accepted']);

        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->count();
    }

    public function alreadyReservedForBooks(int $studentID, array $bookIDs): bool
    {
        return Reservation::where('studentID', $studentID)
            ->whereIn('status', ['Waiting', 'Accepted'])
            ->whereIn('bookID', $bookIDs)
            ->exists();
    }

    public function earliestWaitingForBook(int $bookID): ?Reservation
    {
        return Reservation::where('bookID', $bookID)
            ->where('status', 'Waiting')
            ->orderBy('reservedAt')
            ->first();
    }

    public function queuePositionAheadOf(int $bookID, \DateTimeInterface $reservedAt): int
    {
        return Reservation::where('bookID', $bookID)
            ->where('status', 'Waiting')
            ->where('reservedAt', '<', $reservedAt)
            ->count() + 1;
    }
}
