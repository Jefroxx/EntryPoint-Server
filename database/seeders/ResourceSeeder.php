<?php

namespace Database\Seeders;

use App\Models\Librarian;
use App\Models\Resource;
use App\Models\ResourceUsageLog;
use App\Models\Student;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class ResourceSeeder extends Seeder
{
    public function run(): void
    {
        $resources = Resource::factory()->count(10)->create();
        $students = Student::where('registrationStatus', 'approved')->get();
        $librarianID = Librarian::first()?->librarianID;

        if ($students->isEmpty()) {
            return;
        }

        // A couple of past (ended) sessions per resource, plus one resource
        // currently in use (no endTime) so the "active session" views have data.
        foreach ($resources as $index => $resource) {
            for ($i = 0; $i < random_int(1, 3); $i++) {
                $start = Carbon::now()->subDays(random_int(1, 30))->setTime(random_int(8, 16), 0);

                ResourceUsageLog::create([
                    'uuid'             => Str::uuid(),
                    'resID'            => $resource->resID,
                    'studentID'        => $students->random()->studentID,
                    'staffLibrarianID' => $librarianID,
                    'startTime'        => $start,
                    'endTime'          => (clone $start)->addMinutes(random_int(15, 90)),
                ]);
            }

            if ($index === 0) {
                ResourceUsageLog::create([
                    'uuid'             => Str::uuid(),
                    'resID'            => $resource->resID,
                    'studentID'        => $students->random()->studentID,
                    'staffLibrarianID' => $librarianID,
                    'startTime'        => Carbon::now()->subMinutes(20),
                    'endTime'          => null,
                ]);
                $resource->update(['status' => 'In Use']);
            }
        }
    }
}
