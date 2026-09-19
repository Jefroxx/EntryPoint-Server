<?php

namespace Database\Seeders;

use App\Models\Librarian;
use App\Models\Loan;
use App\Models\SelfReturnReport;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class SelfReturnReportSeeder extends Seeder
{
    public function run(): void
    {
        $librarianID = Librarian::first()?->librarianID;
        $activeLoans = Loan::where('status', 'Active')->inRandomOrder()->limit(3)->get();

        foreach ($activeLoans as $index => $loan) {
            $status = ['Pending', 'Verified', 'Rejected'][$index % 3];

            SelfReturnReport::create([
                'uuid'                  => Str::uuid(),
                'loanID'                => $loan->loanID,
                'verifiedByLibrarianID' => $status === 'Pending' ? null : $librarianID,
                'reportedAt'            => Carbon::now()->subHours(random_int(1, 48)),
                'verificationStatus'    => $status,
            ]);
        }
    }
}
