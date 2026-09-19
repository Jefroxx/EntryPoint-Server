<?php

namespace Database\Seeders;

use App\Models\BookCopy;
use App\Models\Loan;
use App\Models\Penalty;
use App\Models\PenaltyType;
use App\Models\Student;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class LoanSeeder extends Seeder
{
    public function run(): void
    {
        $students = Student::where('registrationStatus', 'approved')->get();

        if ($students->isEmpty()) {
            return;
        }

        // Active loans for copies already marked 'borrowed' by CatalogSeeder.
        BookCopy::where('status', 'borrowed')->with('book')->get()->each(function (BookCopy $copy) use ($students) {
            $checkout = Carbon::now()->subDays(random_int(1, 10));
            $period = random_int(2, 5);
            $dueDate = (clone $checkout)->addDays($period);

            Loan::create([
                'uuid'         => Str::uuid(),
                'studentID'    => $students->random()->studentID,
                'copyID'       => $copy->copyID,
                'loanType'     => $copy->book->areasOfLibrary,
                'checkoutDate' => $checkout,
                'dueDate'      => $dueDate,
                'status'       => 'Active',
            ]);
        });

        // Loan history: some on-time, some late (feeding real penalty rows).
        $historyCopies = BookCopy::where('status', 'available')->inRandomOrder()->limit(60)->get();

        foreach ($historyCopies as $copy) {
            $checkout = Carbon::now()->subDays(random_int(15, 90));
            $period = random_int(2, 5);
            $dueDate = (clone $checkout)->addDays($period);
            $late = random_int(1, 100) <= 25; // ~25% returned late
            $returnDate = $late
                ? (clone $dueDate)->addDays(random_int(1, 6))
                : (clone $dueDate)->subHours(random_int(1, 20));

            $loan = Loan::create([
                'uuid'         => Str::uuid(),
                'studentID'    => $students->random()->studentID,
                'copyID'       => $copy->copyID,
                'loanType'     => $copy->book->areasOfLibrary ?? 'circulation',
                'checkoutDate' => $checkout,
                'dueDate'      => $dueDate,
                'returnDate'   => $returnDate,
                'status'       => 'Returned',
            ]);

            if ($late) {
                $type = PenaltyType::where('category', $loan->loanType)->first();
                $rule = $type?->rules()->first();

                if ($rule) {
                    $daysLate = $dueDate->diffInDays($returnDate);
                    Penalty::create([
                        'uuid'          => Str::uuid(),
                        'loanID'        => $loan->loanID,
                        'penaltyTypeID' => $rule->penaltyTypeID,
                        'amount'        => round($rule->rate * max($daysLate, 1), 2),
                        'computedAt'    => $returnDate,
                        'settledAt'     => random_int(0, 1) ? $returnDate : null,
                        'paymentStatus' => random_int(0, 1) ? 'Paid' : 'Unpaid',
                    ]);
                }
            }
        }
    }
}
