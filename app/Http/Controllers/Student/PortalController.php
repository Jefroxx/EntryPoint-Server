<?php

namespace App\Http\Controllers\Student;

use App\Http\Controllers\Controller;
use App\Models\Author;
use App\Models\Book;
use App\Models\BookSubject;
use App\Models\Loan;
use App\Models\Penalty;
use App\Models\Reservation;
use App\Models\Wishlist;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Read-only endpoints that power the student app: profile + counters, own
 * loans / fines / visits / redemptions, and a catalog that carries live
 * availability. Everything here is scoped to the signed-in student.
 */
class PortalController extends Controller
{
    private const MAX_ACTIVE_RESERVATIONS = 3;

    public function profile(Request $request)
    {
        $student = $request->user()->student;
        $user = $request->user();

        $activeReservations = Reservation::where('studentID', $student->studentID)
            ->whereIn('status', ['Waiting', 'Accepted'])->count();

        $unpaid = Penalty::whereHas('loan', fn ($q) => $q->where('studentID', $student->studentID))
            ->where('paymentStatus', 'Unpaid')
            ->sum('amount');

        $activeLoans = $student->loans()->where('status', 'Active');

        return response()->json([
            'profile' => [
                'firstName'          => $user->firstName,
                'middleInitial'      => $user->middleInitial,
                'lastName'           => $user->lastName,
                'fullName'           => $user->fullName,
                'email'              => $user->email,
                'phoneNumber'        => $user->phoneNumber,
                'address'            => $user->address,
                'studentIDNumber'    => $student->studentIDNumber,
                'academicProgram'    => $student->academicProgram,
                'barcodeValue'       => $student->barcodeValue,
                'registrationStatus' => $student->registrationStatus,
            ],
            'points'      => (int) $student->knowledgeScore,
            'visitStreak' => (int) $student->visitStreak,
            'stats'       => [
                'activeLoans'        => (clone $activeLoans)->count(),
                'overdueLoans'       => (clone $activeLoans)->where('dueDate', '<', now())->count(),
                'readyReservations'  => Reservation::where('studentID', $student->studentID)->where('status', 'Accepted')->count(),
                'activeReservations' => $activeReservations,
                'slotsLeft'          => max(self::MAX_ACTIVE_RESERVATIONS - $activeReservations, 0),
                'unpaidFines'        => (float) $unpaid,
                'wishlistCount'      => Wishlist::where('studentID', $student->studentID)->count(),
                'cartCount'          => Wishlist::where('studentID', $student->studentID)->where('inCart', true)->count(),
            ],
        ]);
    }

    public function loans(Request $request)
    {
        $student = $request->user()->student;

        $loans = $student->loans()
            ->with(['copy.book' => fn ($q) => $q->withTrashed(), 'copy.book.authors', 'copy.book.subject', 'selfReturnReport'])
            ->orderByRaw("CASE WHEN status = 'Active' THEN 0 ELSE 1 END")
            ->orderBy('dueDate')
            ->get()
            ->map(function (Loan $loan) {
                $reported = $loan->status === 'Active'
                    && $loan->selfReturnReport
                    && $loan->selfReturnReport->verificationStatus === 'Pending';

                return [
                    'loanID'       => $loan->loanID,
                    'status'       => $reported ? 'Reported' : $loan->status,
                    'checkoutDate' => $loan->checkoutDate,
                    'dueDate'      => $loan->dueDate,
                    'returnDate'   => $loan->returnDate,
                    // whole days from today to the due date; negative = overdue
                    'daysLeft'     => (int) now()->startOfDay()->diffInDays(Carbon::parse($loan->dueDate)->startOfDay(), false),
                    'book'         => $this->bookBrief($loan->copy->book),
                ];
            });

        return response()->json(['loans' => $loans]);
    }

    public function penalties(Request $request)
    {
        $student = $request->user()->student;

        $penalties = Penalty::whereHas('loan', fn ($q) => $q->where('studentID', $student->studentID))
            ->with(['loan.copy.book' => fn ($q) => $q->withTrashed(), 'loan.copy.book.authors', 'loan.copy.book.subject'])
            ->orderByDesc('computedAt')
            ->get()
            ->map(function (Penalty $penalty) {
                $loan = $penalty->loan;
                $book = $loan->copy->book;
                $rule = Penalty::ruleForArea($book->circulationType);
                $end = $loan->returnDate ?? now();

                $unit = $rule?->rateUnit === 'hour' ? 'hour' : 'day';
                $units = $unit === 'hour'
                    ? (int) ceil(Carbon::parse($loan->dueDate)->diffInMinutes($end) / 60)
                    : (int) ceil(Carbon::parse($loan->dueDate)->diffInMinutes($end) / (60 * 24));

                return [
                    'penaltyID'     => $penalty->penaltyID,
                    'amount'        => (float) $penalty->amount,
                    'paymentStatus' => $penalty->paymentStatus,
                    'computedAt'    => $penalty->computedAt,
                    'settledAt'     => $penalty->settledAt,
                    'loanReturned'  => $loan->status === 'Returned',
                    'rate'          => $rule ? (float) $rule->rate : null,
                    'rateUnit'      => $unit,
                    'unitsLate'     => max($units, 0),
                    'book'          => $this->bookBrief($book),
                ];
            });

        return response()->json([
            'penalties'   => $penalties,
            'unpaidTotal' => $penalties->where('paymentStatus', 'Unpaid')->sum('amount'),
        ]);
    }

    public function attendance(Request $request)
    {
        $student = $request->user()->student;

        $logs = $student->attendanceLogs()->orderByDesc('entryTime')->limit(30)->get();

        return response()->json([
            'visits' => $logs->take(10)->values(),
            // dates (Y-m-d) with at least one visit in the last 7 days, oldest first strip is built client-side
            'recentDates' => $logs
                ->filter(fn ($log) => $log->entryTime->greaterThanOrEqualTo(now()->subDays(6)->startOfDay()))
                ->map(fn ($log) => $log->entryTime->toDateString())
                ->unique()->values(),
        ]);
    }

    public function redemptions(Request $request)
    {
        $student = $request->user()->student;

        $rows = $student->pointRedemptions()->with('item')->orderByDesc('redeemedAt')->get()
            ->map(fn ($r) => [
                'redemptionID' => $r->redemptionID,
                'itemName'     => $r->item?->name ?? 'Removed item',
                'quantity'     => $r->quantity,
                'pointsSpent'  => $r->pointsSpent,
                'status'       => $r->fulfillmentStatus,
                'redeemedAt'   => $r->redeemedAt,
            ]);

        return response()->json(['redemptions' => $rows]);
    }

    /** Catalog with live availability: search, subject, "available now", paginated. */
    public function catalog(Request $request)
    {
        $v = $request->validate([
            'search'        => ['nullable', 'string', 'max:255'],
            'subjectID'     => ['nullable', 'integer', 'exists:book_subjects,subjectID'],
            'availableOnly' => ['nullable', 'boolean'],
            'perPage'       => ['nullable', 'integer', 'min:1', 'max:60'],
        ]);

        $paginator = Book::with(['subject', 'authors'])
            ->withCount([
                'copies as total_copies'     => fn ($q) => $q->where('status', '!=', 'retired'),
                'copies as available_copies' => fn ($q) => $q->where('status', 'available'),
            ])
            ->when($v['search'] ?? null, function ($q, $term) {
                $q->where(function ($w) use ($term) {
                    $w->where('title', 'like', "%{$term}%")
                        ->orWhere('callNumber', 'like', "%{$term}%")
                        ->orWhereHas('authors', fn ($a) => $a->where('name', 'like', "%{$term}%"))
                        ->orWhereHas('subject', fn ($s) => $s->where('name', 'like', "%{$term}%"));
                });
            })
            ->when($v['subjectID'] ?? null, fn ($q, $id) => $q->where('subjectID', $id))
            ->when($request->boolean('availableOnly'), fn ($q) => $q->whereHas('copies', fn ($c) => $c->where('status', 'available')))
            ->orderBy('title')
            ->paginate($v['perPage'] ?? 24);

        $queues = $this->queueLengths($paginator->getCollection()->pluck('bookID')->all());
        $paginator->setCollection($paginator->getCollection()->map(fn (Book $b) => $this->catalogRow($b, $queues[$b->bookID] ?? 0)));

        return response()->json($paginator);
    }

    public function catalogShow(Book $book)
    {
        $book->load(['subject', 'authors'])->loadCount([
            'copies as total_copies'     => fn ($q) => $q->where('status', '!=', 'retired'),
            'copies as available_copies' => fn ($q) => $q->where('status', 'available'),
        ]);

        return response()->json([
            'book' => $this->catalogRow($book, $this->queueLengths([$book->bookID])[$book->bookID] ?? 0),
        ]);
    }

    public function subjects()
    {
        $subjects = BookSubject::withCount('books')->orderBy('name')->get()
            ->filter(fn ($s) => $s->books_count > 0)
            ->map(fn ($s) => ['subjectID' => $s->subjectID, 'name' => $s->name, 'books' => $s->books_count])
            ->values();

        return response()->json(['subjects' => $subjects]);
    }

    /* ---------------------------------------------------------------- */

    private function queueLengths(array $bookIDs): array
    {
        if (! $bookIDs) {
            return [];
        }

        return Reservation::whereIn('bookID', $bookIDs)->where('status', 'Waiting')
            ->selectRaw('bookID, COUNT(*) as total')->groupBy('bookID')
            ->pluck('total', 'bookID')->all();
    }

    private function catalogRow(Book $book, int $queue): array
    {
        return $this->bookBrief($book) + [
            'callNumber'      => $book->callNumber,
            'isbn'            => $book->isbn,
            'publicationYear' => $book->publicationYear,
            'shelfLocation'   => $book->shelfLocation,
            'circulationType' => $book->circulationType,
            'totalCopies'     => (int) ($book->total_copies ?? 0),
            'availableCopies' => (int) ($book->available_copies ?? 0),
            'queueLength'     => $queue,
        ];
    }

    private function bookBrief(Book $book): array
    {
        return [
            'bookID'        => $book->bookID,
            'title'         => $book->title,
            'coverImageURL' => $book->coverImageURL,
            'subject'       => $book->subject ? ['subjectID' => $book->subject->subjectID, 'name' => $book->subject->name] : null,
            'authors'       => $book->authors->map(fn (Author $a) => $a->name)->values(),
        ];
    }
}
