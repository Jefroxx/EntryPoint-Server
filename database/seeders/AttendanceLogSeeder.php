<?php

namespace Database\Seeders;

use App\Models\AttendanceLog;
use App\Models\Librarian;
use App\Models\Student;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Library visits for the demo: a few weeks of them (so Reports has trends and weekday patterns), each recorded by the
 * staff account that scanned the member in and out; Bea's six days in a row (her visit streak); three people in the
 * library right now, Faith, Gab and a walk-in visitor; and a visitor from earlier today who has left.
 */
class AttendanceLogSeeder extends Seeder
{
    public function run(): void
    {
        $desk = Librarian::whereIn('role', ['librarian', 'assistant'])->pluck('librarianID')->all();
        $by = fn (int $i) => $desk[$i % count($desk)];
        $today = Carbon::today();

        // A steady few visits a week from every approved member except the brand-new one (Hannah has none yet).
        $hannah = StudentSeeder::find('hannah')->studentID;
        $members = Student::where('registrationStatus', 'approved')->where('studentID', '!=', $hannah)->get();
        $n = 0;
        foreach ($members as $m => $member) {
            for ($day = 7; $day <= 52; $day += 3 + ($m % 4)) {
                $date = $today->copy()->subDays($day);
                if ($date->isSunday()) {
                    continue;
                }
                $in = $date->setTime(8 + ($day + $m) % 8, ($day * 7 + $m * 13) % 60);
                $this->visit($member->studentID, $in, $in->copy()->addMinutes(25 + ($day * 11 + $m * 17) % 120), $by($n++));
            }
        }

        // Bea: six days in a row up to yesterday.
        $bea = StudentSeeder::find('bea')->studentID;
        for ($day = 6; $day >= 1; $day--) {
            $in = $today->copy()->subDays($day)->setTime(13, 10 + $day);
            $this->visit($bea, $in, $in->copy()->addMinutes(80), $by($n++));
        }

        // In the library right now.
        foreach (['faith' => 40, 'gab' => 15] as $key => $minutes) {
            $this->visit(StudentSeeder::find($key)->studentID, now()->subMinutes($minutes), null, $by($n++));
        }
        AttendanceLog::create([
            'uuid' => Str::uuid(), 'visitorName' => 'Ramon Dizon', 'visitorPurpose' => 'Research for a thesis',
            'entryTime' => now()->subMinutes(25), 'checkedInBy' => $by($n++),
        ]);
        // A visitor from earlier today who has left.
        AttendanceLog::create([
            'uuid' => Str::uuid(), 'visitorName' => 'Grace Uy', 'visitorPurpose' => 'Parent picking up a student',
            'entryTime' => $today->copy()->setTime(7, 45), 'exitTime' => $today->copy()->setTime(8, 5),
            'checkedInBy' => $by($n), 'checkedOutBy' => $by($n),
        ]);
    }

    private function visit(int $studentID, Carbon $in, ?Carbon $out, string|int $staff): void
    {
        AttendanceLog::create([
            'uuid'         => Str::uuid(),
            'studentID'    => $studentID,
            'entryTime'    => $in,
            'exitTime'     => $out,
            'checkedInBy'  => $staff,
            'checkedOutBy' => $out ? $staff : null,
        ]);
    }
}
