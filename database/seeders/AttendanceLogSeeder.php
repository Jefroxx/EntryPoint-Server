<?php

namespace Database\Seeders;

use App\Models\AttendanceLog;
use App\Models\Student;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class AttendanceLogSeeder extends Seeder
{
    public function run(): void
    {
        $students = Student::where('registrationStatus', 'approved')->get();

        if ($students->isEmpty()) {
            return;
        }

        // Spread visits across the last ~8 weeks so the Reports
        // "visits by weekday" chart has a real distribution to show.
        foreach ($students as $student) {
            $visits = random_int(2, 12);

            for ($i = 0; $i < $visits; $i++) {
                $day = Carbon::now()->subDays(random_int(1, 56))->setTime(random_int(7, 17), random_int(0, 59));

                AttendanceLog::create([
                    'uuid'      => Str::uuid(),
                    'studentID' => $student->studentID,
                    'entryTime' => $day,
                    'exitTime'  => (clone $day)->addMinutes(random_int(15, 150)),
                ]);
            }
        }

        // A couple of students currently checked in right now.
        foreach ($students->random(min(3, $students->count()))->all() as $student) {
            AttendanceLog::factory()->stillCheckedIn()->create([
                'studentID' => $student->studentID,
                'entryTime' => Carbon::now()->subMinutes(random_int(5, 45)),
            ]);
        }
    }
}
