<?php

namespace App\Services;

use App\Models\Loan;
use App\Models\Resource;
use App\Repositories\Contracts\StudentRepositoryInterface;
use Illuminate\Validation\ValidationException;

/**
 * The one scanner. Every barcode goes to the same place and the code itself says what to do:
 *
 *   L-000123  a borrowing receipt   -> the book is handed in; it is now in the librarian's hands
 *   R-000123  a reservation slip    -> open the checkout for that reservation
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
        return match ($this->kindOf($code)) {
            'loan'        => $this->receipt($code),
            'reservation' => $this->reservation($code),
            'facility'    => $this->facility($code),
            default       => $armedResID ? $this->startFacilityFor($code, $armedResID, $librarianID) : $this->attendance($code),
        };
    }

    private function kindOf(string $code): string
    {
        return match (true) {
            (bool) preg_match('/^L-\d+$/i', $code) => 'loan',
            (bool) preg_match('/^R-\d+$/i', $code) => 'reservation',
            (bool) preg_match('/^F-\d+$/i', $code) => 'facility',
            default                                => 'student',
        };
    }

    private function receipt(string $code): array
    {
        $loan = $this->circulation->findByReceiptCode($code);

        if (! $loan) {
            $this->fail('No loan matches that receipt.');
        }

        $loan = $this->orFail(fn () => $this->circulation->receiveBook($loan));
        $loan->loadMissing(['student.user', 'copy.book']);

        return [
            'type'    => 'loan_received',
            'message' => 'Book received. Check it for damage.',
            'loan'    => [
                'loanID'          => $loan->loanID,
                'receiptNumber'   => 'L-' . str_pad((string) $loan->loanID, 6, '0', STR_PAD_LEFT),
                'bookTitle'       => $loan->copy->book->title,
                'accessionNumber' => $loan->copy->accessionNumber,
                'studentName'     => $loan->student->user->fullName,
                'studentIDNumber' => $loan->student->studentIDNumber,
                'dueDate'         => $loan->dueDate?->toIso8601String(),
                'returnDate'      => $loan->returnDate?->toIso8601String(),
                'wasLate'         => $loan->dueDate && $loan->returnDate && $loan->returnDate->greaterThan($loan->dueDate),
                'fineAmount'      => (float) $loan->penalties()->sum('amount'),
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
                'pickupCode'      => $reservation->pickupCode,
                'studentID'       => $reservation->studentID,
                'bookID'          => $reservation->bookID,
                'studentName'     => $reservation->student->user->fullName,
                'studentIDNumber' => $reservation->student->studentIDNumber,
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
            $this->fail('Barcode not recognized.');
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
                'program'         => $student->academicProgram,
                'studentIDNumber' => $student->studentIDNumber,
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
                'program'         => $student->academicProgram,
                'studentIDNumber' => $student->studentIDNumber,
                'visitStreak'     => $student->visitStreak,
            ],
            'durationMinutes' => $result['durationMinutes'],
            'autoClosed'      => $result['autoClosed'],
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
