<?php

namespace App\Repositories\Eloquent;

use App\Models\Loan;
use App\Repositories\Contracts\LoanRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as BaseCollection;

class EloquentLoanRepository extends BaseRepository implements LoanRepositoryInterface
{
    public function __construct(Loan $model)
    {
        parent::__construct($model);
    }

    public function loadForResponse(Loan $loan): Loan
    {
        return $loan->load(['student.user', 'copy.book']);
    }

    public function overdueActive(): Collection
    {
        return Loan::where('status', 'Active')
            ->where('dueDate', '<', now())
            ->with('copy.book')
            ->get();
    }

    public function dueSoonNotUnnotified(int $windowHours): Collection
    {
        return Loan::where('status', 'Active')
            ->whereNull('dueSoonNotifiedAt')
            ->where('dueDate', '>', now())
            ->where('dueDate', '<=', now()->addHours($windowHours))
            ->with('copy.book')
            ->get();
    }

    public function activeCount(): int
    {
        return Loan::where('status', 'Active')->count();
    }

    public function countsForBook(int $bookID): array
    {
        $loans = Loan::whereHas('copy', fn ($copy) => $copy->where('bookID', $bookID));

        return [
            'total'  => (clone $loans)->count(),
            'active' => $loans->where('status', 'Active')->count(),
        ];
    }

    public function forStudentWithBooks(int $studentID): Collection
    {
        return Loan::where('studentID', $studentID)
            ->with([
                'copy.book' => fn ($query) => $query->withTrashed(),
                'copy.book.authors',
                'copy.book.subject',
                'selfReturnReport',
            ])
            ->orderByRaw("CASE WHEN status = 'Active' THEN 0 ELSE 1 END")
            ->orderBy('dueDate')
            ->get();
    }

    public function activeCountForStudent(int $studentID): int
    {
        return Loan::where('studentID', $studentID)->where('status', 'Active')->count();
    }

    public function overdueCountForStudent(int $studentID): int
    {
        return Loan::where('studentID', $studentID)
            ->where('status', 'Active')
            ->where('dueDate', '<', now())
            ->count();
    }

    public function countByDayBetween(string $dateColumn, \DateTimeInterface $start, \DateTimeInterface $end): BaseCollection
    {
        // Only ever called internally with a literal column name (never
        // request input) — whitelisted anyway before it reaches raw SQL.
        if (! in_array($dateColumn, ['checkoutDate', 'returnDate'], true)) {
            throw new \InvalidArgumentException("Unsupported date column: {$dateColumn}");
        }

        return Loan::whereNotNull($dateColumn)
            ->whereBetween($dateColumn, [$start, $end])
            ->selectRaw("DATE({$dateColumn}) as day, COUNT(*) as total")
            ->groupBy('day')
            ->pluck('total', 'day');
    }

    public function recentWithRelations(int $limit): Collection
    {
        return Loan::with(['student.user', 'copy.book'])
            ->orderByDesc('checkoutDate')
            ->limit($limit)
            ->get();
    }

    public function overdueWithRelations(int $limit): Collection
    {
        return Loan::with(['student.user', 'copy.book'])
            ->where('status', 'Active')
            ->where('dueDate', '<', now())
            ->orderBy('dueDate')
            ->limit($limit)
            ->get();
    }

    public function paginate(?string $search, ?string $status, int $perPage): LengthAwarePaginator
    {
        return Loan::with(['student.user', 'copy.book'])
            ->when($search, fn ($query, $term) => $query->whereHas(
                'copy.book',
                fn ($bq) => $bq->where('title', 'like', "%{$term}%")
            )->orWhereHas(
                'student.user',
                fn ($uq) => $uq->where('firstName', 'like', "%{$term}%")->orWhere('lastName', 'like', "%{$term}%")
            ))
            ->when($status === 'active', fn ($query) => $query->where('status', 'Active')->where('dueDate', '>=', now()))
            ->when($status === 'overdue', fn ($query) => $query->where('status', 'Active')->where('dueDate', '<', now()))
            ->when($status === 'returned', fn ($query) => $query->where('status', 'Returned'))
            ->orderByDesc('checkoutDate')
            ->paginate($perPage);
    }

    public function stats(): array
    {
        return [
            'active'  => Loan::where('status', 'Active')->count(),
            'dueSoon' => Loan::where('status', 'Active')
                ->where('dueDate', '>=', now())
                ->where('dueDate', '<=', now()->addHours(24))
                ->count(),
            'overdue' => Loan::where('status', 'Active')->where('dueDate', '<', now())->count(),
        ];
    }

    public function todayCounts(): array
    {
        return [
            'checkedOut' => Loan::whereDate('checkoutDate', today())->count(),
            'returned'   => Loan::whereDate('returnDate', today())->count(),
            // Still out and due later today (once the time passes it counts as overdue instead).
            'dueToday'   => Loan::where('status', 'Active')
                ->whereBetween('dueDate', [now(), now()->endOfDay()])
                ->count(),
        ];
    }

    public function countCheckoutsBetween(\DateTimeInterface $start, \DateTimeInterface $end): int
    {
        return Loan::whereBetween('checkoutDate', [$start, $end])->count();
    }

    public function onTimeReturnRate(\DateTimeInterface $start, \DateTimeInterface $end): ?float
    {
        $returned = Loan::whereNotNull('returnDate')->whereBetween('returnDate', [$start, $end]);
        $total = $returned->count();

        if ($total === 0) {
            return null;
        }

        $onTime = (clone $returned)->whereColumn('returnDate', '<=', 'dueDate')->count();

        return round(($onTime / $total) * 100, 1);
    }

    public function countsByMonthBetween(\DateTimeInterface $start, \DateTimeInterface $end): array
    {
        $checkedOut = Loan::whereBetween('checkoutDate', [$start, $end])
            ->selectRaw("DATE_FORMAT(checkoutDate, '%Y-%m') as ym, COUNT(*) as total")
            ->groupBy('ym')
            ->pluck('total', 'ym');

        $returned = Loan::whereNotNull('returnDate')
            ->whereBetween('returnDate', [$start, $end])
            ->selectRaw("DATE_FORMAT(returnDate, '%Y-%m') as ym, COUNT(*) as total")
            ->groupBy('ym')
            ->pluck('total', 'ym');

        return ['checkedOut' => $checkedOut, 'returned' => $returned];
    }

    public function topBooksBetween(\DateTimeInterface $start, \DateTimeInterface $end, int $limit): BaseCollection
    {
        return Loan::join('book_copies', 'loans.copyID', '=', 'book_copies.copyID')
            ->join('books', 'book_copies.bookID', '=', 'books.bookID')
            ->whereBetween('loans.checkoutDate', [$start, $end])
            ->selectRaw('books.title as title, COUNT(*) as total')
            ->groupBy('books.title')
            ->orderByDesc('total')
            ->limit($limit)
            ->get();
    }
}
