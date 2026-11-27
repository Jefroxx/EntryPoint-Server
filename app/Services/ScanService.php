<?php

namespace App\Services;

use App\Models\BookCopy;
use App\Models\Loan;
use App\Models\Resource;
use App\Repositories\Contracts\StudentRepositoryInterface;
use Illuminate\Validation\ValidationException;

/**
 * The one scanner. Every barcode goes to the same place and the code itself says what to do:
 *
 *   L-000123  a borrowing receipt   -> that loan, for the return (the station opens the Return window for it in
 *   BK-XXXXX  a book copy's label      the librarian's main tab; nothing is changed until the librarian confirms there)
 *   R-000123  a reservation slip    -> open the checkout for that reservation (also in the main tab)
 *   F-000012  a facility's label    -> start (or, if it's in use, end) a session on that computer / room
 *   anything else is a student's ID -> attendance, or, if a facility was scanned just before, that
 *                                      student starts using the facility
 */
class ScanService
{
    public function __construct(
        private AttendanceService $attendance,
        private CirculationService $circulation,
        private ReservationService $reservations,
        private ResourceService $resources,
        private StudentRepositoryInterface $students,
    ) {
    }

    /**
     * @param  ?int  $armedResID  a facility the librarian scanned a moment ago, waiting for the student's ID
     */
    public function handle(string $code, ?int $armedResID, int $librarianID): array
    {
        $code = $this->normalise($code);

        return match ($this->kindOf($code)) {
            'loan'        => $this->receipt($code),
            'copy'        => $this->bookCopy($code),
            'reservation' => $this->reservation($code),
            'facility'    => $this->facility($code),
            default       => $armedResID ? $this->startFacilityFor($code, $armedResID, $librarianID) : $this->attendance($code),
        };
    }

    /**
     * What a reader hands over isn't always only the code: some scanners and decoders add a symbology prefix ("]C0"),
     * an invisible separator or a space. A receipt, slip, facility or book label code is taken from inside the read
     * when it stands on its own, so a real slip isn't turned away as "not a slip". Anything else (a student's ID) is
     * used as read, minus invisible characters.
     */
    public function normalise(string $code): string
    {
        $clean = trim(preg_replace('/[\x00-\x1F\x7F]+/', ' ', $code));
        // A symbology identifier ("]C0" = Code 128) that some scanners put in front of every code.
        $clean = preg_replace('/^\][A-Za-z][0-9A-Za-z]/', '', $clean);

        if (preg_match('/(?:^|[^A-Z0-9])([LRF]-\d{1,9}|BK-[A-Z0-9]+)(?![A-Z0-9])/i', $clean, $m)) {
            return strtoupper($m[1]);
        }

        return $clean;
    }

    private function kindOf(string $code): string
    {
        return match (true) {
            (bool) preg_match('/^L-\d+$/i', $code) => 'loan',
            (bool) preg_match('/^R-\d+$/i', $code) => 'reservation',
            (bool) preg_match('/^F-\d+$/i', $code) => 'facility',
            // The barcode printed on a copy's label (BookCopy::barcodeValue, e.g. BK-0OUUNFKFPR).
            (bool) preg_match('/^BK-[A-Z0-9]+$/i', $code) => 'copy',
            default                                => 'student',
        };
    }

    private function receipt(string $code): array
    {
        $loan = $this->circulation->findByReceiptCode($code);

        if (! $loan) {
            $this->fail('No loan matches that receipt.');
        }

        if (! in_array($loan->status, ['Active', 'Received'], true)) {
            $this->fail('This loan is already closed: the book was returned.');
        }

        return $this->forReturn($loan);
    }

    /** A book's own label: the loan it is out on, for the return. */
    private function bookCopy(string $code): array
    {
        $copy = BookCopy::with('book')->where('barcodeValue', strtoupper($code))->first();

        if (! $copy) {
            $this->fail('No book matches that barcode.');
        }

        $loan = Loan::where('copyID', $copy->copyID)->whereIn('status', ['Active', 'Received'])->latest('checkoutDate')->first();

        if (! $loan) {
            $this->fail("\"{$copy->book?->title}\" (accession no. {$copy->accessionNumber}) isn't checked out, so there is nothing to return.");
        }

        return $this->forReturn($loan);
    }

    /**
     * What the station needs to open the return in the librarian's main tab. Nothing is changed here: an Active loan
     * gets the usual Return window (with its late fee), and one already handed in (Received) the check that finishes it.
     */
    private function forReturn(Loan $loan): array
    {
        $loan->loadMissing(['student.user', 'copy.book']);
        $received = $loan->status === 'Received';

        return [
            'type'    => 'loan_return',
            'message' => $received ? 'Handed in already. Check it for damage to finish the return.' : 'Open the return.',
            'loan'    => [
                'loanID'          => $loan->loanID,
                'uuid'            => $loan->uuid,
                'status'          => $loan->status,
                'receiptNumber'   => 'L-' . str_pad((string) $loan->loanID, 6, '0', STR_PAD_LEFT),
                'bookTitle'       => $loan->copy->book->title,
                'accessionNumber' => $loan->copy->accessionNumber,
                'studentName'     => $loan->student->user->fullName,
                'memberIDNumber'  => $loan->student->memberIDNumber,
                'dueDate'         => $loan->dueDate?->toIso8601String(),
                'isOverdue'       => ! $received && $loan->dueDate && $loan->dueDate->isPast(),
            ],
        ];
    }

    private function reservation(string $code): array
    {
        $reservation = $this->orFail(fn () => $this->reservations->findByPickupCode($code));

        return [
            'type'        => 'reservation',
            'message'     => 'Reservation found. Open the checkout.',
            'reservation' => [
                'reservationID'   => $reservation->reservationID,
                'uuid'            => $reservation->uuid,
                'pickupCode'      => $reservation->pickupCode,
                'studentID'       => $reservation->studentID,
                'bookID'          => $reservation->bookID,
                'studentName'     => $reservation->student->user->fullName,
                'memberIDNumber' => $reservation->student->memberIDNumber,
                'bookTitle'       => $reservation->book->title,
            ],
        ];
    }

    private function facility(string $code): array
    {
        $resource = preg_match('/^F-(\d+)$/i', $code, $m)
            ? Resource::with('activeUsage.student.user')->find((int) $m[1])
            : null;

        if (! $resource) {
            $this->fail('No facility matches that label.');
        }

        // Scanning a facility that is in use ends that session.
        if ($resource->activeUsage) {
            $log = $this->orFail(fn () => $this->resources->endSession($resource->activeUsage));

            return [
                'type'     => 'facility_ended',
                'message'  => "{$resource->name} is free again.",
                'resource' => $this->resourceSummary($resource),
                'student'  => $log->student->user->fullName,
                'minutes'  => (int) $log->startTime->diffInMinutes($log->endTime),
            ];
        }

        if ($resource->status !== 'Available') {
            $this->fail("{$resource->name} is currently {$resource->status}.");
        }

        // Otherwise it waits for the student's ID, which the station sends back with this facility.
        return [
            'type'     => 'facility',
            'message'  => "{$resource->name} ready. Scan the student's ID.",
            'resource' => $this->resourceSummary($resource),
        ];
    }

    private function startFacilityFor(string $code, int $resID, int $librarianID): array
    {
        $student = $this->students->findForScan($code);

        if (! $student) {
            $this->fail('Barcode not recognized: read "' . AttendanceService::shownRead($code) . '".');
        }

        if (! $student->isApproved()) {
            $this->fail('This account is not approved yet.');
        }

        $log = $this->orFail(fn () => $this->resources->startSession(['resID' => $resID, 'studentID' => $student->studentID], $librarianID));

        return [
            'type'     => 'facility_started',
            'message'  => "{$log->resource->name} started.",
            'resource' => $this->resourceSummary($log->resource),
            'student'  => [
                'name'            => $student->user->fullName,
                'program'         => $student->academicProgram ?? $student->department,
                'memberIDNumber' => $student->memberIDNumber,
            ],
        ];
    }

    private function attendance(string $code): array
    {
        $result = $this->attendance->scan($code);
        $student = $result['student'];
        $isCheckIn = $result['action'] === 'check_in';

        return [
            'type'            => 'attendance',
            'message'         => $isCheckIn ? 'Checked in.' : 'Checked out.',
            'action'          => $result['action'],
            'log'             => $result['log'],
            'student'         => [
                'name'            => $student->user->fullName,
                'program'         => $student->academicProgram ?? $student->department,
                'memberIDNumber' => $student->memberIDNumber,
                'visitStreak'     => $student->visitStreak,
            ],
            'durationMinutes' => $result['durationMinutes'],
            'autoClosed'      => $result['autoClosed'],
            'endedSession'    => $result['endedSession'] ?? null,
        ];
    }

    private function resourceSummary(Resource $resource): array
    {
        return ['resID' => $resource->resID, 'name' => $resource->name, 'resourceType' => $resource->resourceType];
    }

    /** Runs a service call and turns its validation error into the scanner's one error field, `barcodeValue`. */
    private function orFail(callable $call): mixed
    {
        try {
            return $call();
        } catch (ValidationException $e) {
            $this->fail((string) collect($e->errors())->flatten()->first());
        }
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['barcodeValue' => [$message]]);
    }
}
