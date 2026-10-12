<?php

namespace App\Services;

use App\Models\Student;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Data privacy tools (RA 10173): find a member, hand them a copy of everything the library holds about them, or
 * anonymize their account when they ask to be forgotten.
 *
 * Anonymizing keeps the library's own records (loans, fines, visits) so statistics and accounts stay correct, but they
 * stop being about a named person: the name, email, phone, address, birth date and ID are gone, the account is
 * closed, and the person's name and ID are scrubbed from the audit log.
 */
class PrivacyService
{
    /** The email domain an anonymized account is given: it can never receive mail or sign in. */
    public const REMOVED_DOMAIN = 'removed.invalid';

    public function __construct(private AuditLogService $audit)
    {
    }

    /** A short reference the administrator can quote later without naming anyone. */
    public static function ref(Student $student): string
    {
        return substr((string) $student->uuid, 0, 8);
    }

    // ---------------------------------------------------------------- finding someone

    /** @return array<int, array<string, mixed>> */
    public function search(string $term): array
    {
        $term = trim($term);
        if (mb_strlen($term) < 2) {
            return [];
        }

        return Student::with('user')
            ->join('users', 'users.userID', '=', 'students.studentID')
            ->where(fn ($q) => $q
                ->where('users.firstName', 'like', "%{$term}%")
                ->orWhere('users.lastName', 'like', "%{$term}%")
                ->orWhere('users.email', 'like', "%{$term}%")
                ->orWhere('students.studentIDNumber', 'like', "%{$term}%"))
            ->orderBy('users.firstName')
            ->limit(10)
            ->get('students.*')
            ->map(fn (Student $s) => $this->summary($s))
            ->all();
    }

    private function summary(Student $student): array
    {
        $user = $student->user;

        return [
            'uuid'         => $student->uuid,
            'name'         => $user->fullName,
            'email'        => $user->email,
            'idNumber'     => $student->studentIDNumber,
            'memberType'   => $student->memberType,
            'detail'       => $student->academicProgram ?? $student->department,
            'isDisabled'   => $user->isDisabled(),
            'isAnonymized' => $this->isAnonymized($user),
            // What stands in the way of anonymizing, so the screen can say so before anyone asks.
            'blockers'     => $this->blockers($student),
        ];
    }

    private function isAnonymized(User $user): bool
    {
        return str_ends_with($user->email, '@' . self::REMOVED_DOMAIN);
    }

    // ---------------------------------------------------------------- a copy of their data

    /** Everything the library holds about this person, ready to hand over. */
    public function export(User $actor, Student $student): array
    {
        $user = $student->user;
        $id = $student->studentID;

        $data = [
            'exportedAt' => now()->toIso8601String(),
            'profile' => [
                'name'          => $user->fullName,
                'email'         => $user->email,
                'phoneNumber'   => $user->phoneNumber,
                'address'       => $user->address,
                'birthDate'     => $user->birthDate?->toDateString(),
                'memberType'    => $student->memberType,
                'idNumber'      => $student->studentIDNumber,
                'program'       => $student->academicProgram,
                'department'    => $student->department,
                'registration'  => $student->registrationStatus,
                'registeredAt'  => $user->created_at?->toIso8601String(),
                'knowledgeScore' => $student->knowledgeScore,
                'visitStreak'   => $student->visitStreak,
            ],
            'loans' => DB::table('loans')
                ->join('book_copies', 'book_copies.copyID', '=', 'loans.copyID')
                ->join('books', 'books.bookID', '=', 'book_copies.bookID')
                ->where('loans.studentID', $id)->orderBy('loans.loanID')
                ->get(['books.title as book', 'book_copies.accessionNumber', 'loans.checkoutDate', 'loans.dueDate', 'loans.returnDate', 'loans.status']),
            'fines' => DB::table('penalties')
                ->join('loans', 'loans.loanID', '=', 'penalties.loanID')
                ->join('book_copies', 'book_copies.copyID', '=', 'loans.copyID')
                ->join('books', 'books.bookID', '=', 'book_copies.bookID')
                ->where('loans.studentID', $id)->orderBy('penalties.penaltyID')
                ->get(['books.title as book', 'penalties.amount', 'penalties.paymentStatus', 'penalties.computedAt', 'penalties.settledAt']),
            'reservations' => DB::table('reservations')
                ->join('books', 'books.bookID', '=', 'reservations.bookID')
                ->where('reservations.studentID', $id)->orderBy('reservations.reservationID')
                ->get(['books.title as book', 'reservations.status', 'reservations.reservedAt']),
            'visits' => DB::table('attendance_logs')->where('studentID', $id)->orderBy('logID')->get(['entryTime', 'exitTime']),
            'facilityUse' => DB::table('resource_usage_logs')
                ->join('resources', 'resources.resID', '=', 'resource_usage_logs.resID')
                ->where('resource_usage_logs.studentID', $id)->orderBy('resource_usage_logs.usageID')
                ->get(['resources.name as resource', 'resource_usage_logs.startTime', 'resource_usage_logs.endTime']),
            'wishlist' => DB::table('wishlists')
                ->join('books', 'books.bookID', '=', 'wishlists.bookID')
                ->where('wishlists.studentID', $id)->get(['books.title as book', 'wishlists.inWishlist', 'wishlists.inCart', 'wishlists.addedAt']),
            'bookSuggestions' => DB::table('book_suggestions')->where('studentID', $id)->orderBy('suggestionID')
                ->get(['title', 'author', 'reason', 'status', 'submittedAt']),
            'redemptions' => DB::table('point_redemptions')
                ->join('market_items', 'market_items.itemID', '=', 'point_redemptions.itemID')
                ->where('point_redemptions.studentID', $id)->orderBy('point_redemptions.redemptionID')
                ->get(['market_items.name as item', 'point_redemptions.quantity', 'point_redemptions.pointsSpent', 'point_redemptions.fulfillmentStatus', 'point_redemptions.redeemedAt']),
            'achievements' => DB::table('student_achievement')
                ->join('achievements', 'achievements.achievementID', '=', 'student_achievement.achievementID')
                ->where('student_achievement.studentID', $id)->orderBy('student_achievement.studentAchievementID')
                ->get(['achievements.name as achievement', 'student_achievement.earnedAt', 'student_achievement.redeemedAt']),
            'notifications' => DB::table('notifications')->where('userID', $user->userID)->orderBy('notificationID')->get(['message', 'type', 'sentAt']),
        ];

        $this->audit->record($actor, 'privacy.exported', 'Exported the data of a member (ref ' . self::ref($student) . ')');

        return $data;
    }

    // ---------------------------------------------------------------- being forgotten

    /**
     * Reasons this account cannot be anonymized yet: the library still owes them, or they owe the library. Anonymizing
     * would orphan a book still out or a fine still owed, so those are settled first.
     *
     * @return string[]
     */
    public function blockers(Student $student): array
    {
        $id = $student->studentID;
        $reasons = [];

        if ($this->isAnonymized($student->user)) {
            return ['This account has already been anonymized.'];
        }

        if (($n = DB::table('loans')->where('studentID', $id)->where('status', '!=', 'Returned')->count()) > 0) {
            $reasons[] = $n . ' book' . ($n === 1 ? ' is' : 's are') . ' still out or waiting to be checked.';
        }

        $unpaid = DB::table('penalties')->join('loans', 'loans.loanID', '=', 'penalties.loanID')
            ->where('loans.studentID', $id)->where('penalties.paymentStatus', 'Unpaid')->count();
        if ($unpaid > 0) {
            $reasons[] = $unpaid . ' fine' . ($unpaid === 1 ? ' is' : 's are') . ' still unpaid.';
        }

        if (($n = DB::table('reservations')->where('studentID', $id)->whereIn('status', ['Waiting', 'Accepted'])->count()) > 0) {
            $reasons[] = $n . ' reservation' . ($n === 1 ? ' is' : 's are') . ' still open.';
        }

        if (($n = DB::table('point_redemptions')->where('studentID', $id)->where('fulfillmentStatus', 'Pending')->count()) > 0) {
            $reasons[] = $n . ' reward' . ($n === 1 ? ' is' : 's are') . ' still waiting to be handed out.';
        }

        return $reasons;
    }

    public function anonymize(User $actor, Student $student): void
    {
        $reasons = $this->blockers($student);
        if ($reasons) {
            throw ValidationException::withMessages(['account' => ["This account can't be anonymized yet. " . implode(' ', $reasons)]]);
        }

        $user = $student->user;
        $name = $user->fullName;
        $idNumber = $student->studentIDNumber;
        $ref = self::ref($student);

        DB::transaction(function () use ($student, $user, $ref, $name, $idNumber) {
            $user->tokens()->delete();

            $user->update([
                'firstName'          => 'Removed',
                'middleInitial'      => null,
                'lastName'           => 'Member',
                'email'              => "removed-{$ref}@" . self::REMOVED_DOMAIN,
                'phoneNumber'        => null,
                'birthDate'          => null,
                'address'            => null,
                'password'           => Hash::make(Str::random(40)),
                'emailVerifiedAt'    => null,
                'disabledAt'         => now(),
                'mustChangePassword' => false,
            ]);

            $student->update([
                'studentIDNumber' => "REMOVED-{$ref}",
                'barcodeValue'    => null,
                'academicProgram' => null,
                'department'      => null,
                'knowledgeScore'  => 0,
                'visitStreak'     => 0,
            ]);

            // What only mattered to them goes; the library's own records stay, no longer about a named person.
            DB::table('wishlists')->where('studentID', $student->studentID)->delete();
            DB::table('market_cart_items')->where('studentID', $student->studentID)->delete();
            DB::table('notifications')->where('userID', $user->userID)->delete();

            // Their name and ID must not live on in the audit log's wording either ("Approved a student: ...").
            DB::table('audit_logs')
                ->where(fn ($q) => $q->where('summary', 'like', '%' . $name . '%')->orWhere('summary', 'like', '%' . $idNumber . '%'))
                ->get(['logID', 'summary'])
                ->each(fn ($row) => DB::table('audit_logs')->where('logID', $row->logID)->update([
                    'summary' => str_replace([$name, $idNumber], ['a removed member', 'removed'], $row->summary),
                ]));
        });

        $this->audit->record($actor, 'privacy.anonymized', "Anonymized a member account (ref {$ref})");
    }
}
