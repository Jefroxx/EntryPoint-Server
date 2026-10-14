<?php

namespace App\Services;

use App\Models\Student;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * Creates many member accounts at once from a list (a CSV the administrator uploaded and the screen parsed): students,
 * faculty and staff. Every row is checked first, and a dry run reports what would be created and which rows are
 * skipped and why. Real runs create only the valid rows, each on its own temporary password.
 */
class MemberImportService
{
    public const MAX_ROWS = 500;

    public function __construct(private AuditLogService $audit)
    {
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array{rows: array<int, array>, creatable: int, skipped: int}
     */
    public function check(array $rows): array
    {
        $checked = [];
        $seenEmails = [];
        $seenIds = [];

        foreach ($rows as $index => $row) {
            $clean = $this->clean($row);
            $errors = $this->validate($clean);

            // The same email or ID twice in one file: the first wins, the rest are skipped.
            $email = Str::lower($clean['email']);
            if ($clean['email'] !== '' && isset($seenEmails[$email])) {
                $errors['email'] = 'This email is already used by row ' . $seenEmails[$email] . '.';
            }
            if ($clean['idNumber'] !== '' && isset($seenIds[$clean['idNumber']])) {
                $errors['idNumber'] = 'This ID is already used by row ' . $seenIds[$clean['idNumber']] . '.';
            }
            if (! $errors) {
                $seenEmails[$email] = $index + 1;
                $seenIds[$clean['idNumber']] = $index + 1;
            }

            $checked[] = ['row' => $index + 1, 'data' => $clean, 'errors' => $errors, 'ok' => ! $errors];
        }

        $creatable = count(array_filter($checked, fn ($r) => $r['ok']));

        return ['rows' => $checked, 'creatable' => $creatable, 'skipped' => count($checked) - $creatable];
    }

    /**
     * Creates every valid row. Returns who was created (with the temporary password to hand over) and the rows skipped.
     *
     * @param  array<int, array<string, mixed>>  $rows
     */
    public function import(User $actor, array $rows): array
    {
        $result = $this->check($rows);
        $created = [];

        DB::transaction(function () use ($result, &$created) {
            foreach ($result['rows'] as $item) {
                if (! $item['ok']) {
                    continue;
                }

                $created[] = $this->create($item['data']);
            }
        });

        if ($created) {
            $this->audit->record($actor, 'account.imported', 'Imported ' . count($created) . ' account' . (count($created) === 1 ? '' : 's') . ' from a CSV file');
        }

        return [
            'created' => $created,
            'skipped' => array_values(array_filter($result['rows'], fn ($r) => ! $r['ok'])),
        ];
    }

    private function create(array $data): array
    {
        $password = Str::password(10, letters: true, numbers: true, symbols: false);

        $user = User::create([
            'uuid'               => Str::uuid(),
            'firstName'          => $data['firstName'],
            'middleInitial'      => $data['middleInitial'] ?: null,
            'lastName'           => $data['lastName'],
            'email'              => $data['email'],
            'password'           => Hash::make($password),
            'phoneNumber'        => $data['phoneNumber'] ?: null,
            'userType'           => 'student',
            'emailVerifiedAt'    => now(),
            'mustChangePassword' => true,
        ]);

        Student::create([
            'studentID'          => $user->userID,
            'uuid'               => Str::uuid(),
            'studentIDNumber'    => $data['idNumber'],
            'memberType'         => $data['type'],
            // A student has a program; faculty and staff have a department.
            'academicProgram'    => $data['type'] === 'student' ? $data['programOrDepartment'] : null,
            'department'         => $data['type'] === 'student' ? null : $data['programOrDepartment'],
            'barcodeValue'       => 'STI-' . strtoupper(Str::random(10)),
            'registrationStatus' => 'approved',
            'reviewedAt'         => now(),
        ]);

        return [
            'name'              => $user->fullName,
            'email'             => $user->email,
            'type'              => $data['type'],
            'idNumber'          => $data['idNumber'],
            'temporaryPassword' => $password,
        ];
    }

    /** One row as the screen sent it, trimmed, with every expected key present. */
    private function clean(array $row): array
    {
        $get = fn (string $key) => trim((string) ($row[$key] ?? ''));

        return [
            'type'                => Str::lower($get('type')),
            'idNumber'            => $get('idNumber'),
            'firstName'           => $get('firstName'),
            'middleInitial'       => $get('middleInitial'),
            'lastName'            => $get('lastName'),
            'email'               => $get('email'),
            'programOrDepartment' => $get('programOrDepartment'),
            'phoneNumber'         => $get('phoneNumber'),
        ];
    }

    /** @return array<string, string> field → first message */
    private function validate(array $data): array
    {
        $validator = Validator::make($data, [
            'type'                => ['required', 'in:student,faculty,staff'],
            'idNumber'            => ['required', 'string', 'max:40', 'unique:students,studentIDNumber'],
            'firstName'           => ['required', 'string', 'max:60'],
            'middleInitial'       => ['nullable', 'string', 'max:1'],
            'lastName'            => ['required', 'string', 'max:60'],
            'email'               => ['required', 'email', 'max:120', 'unique:users,email'],
            'programOrDepartment' => ['required', 'string', 'max:80'],
            'phoneNumber'         => ['nullable', 'string', 'max:20'],
        ], [
            'type.in'                     => 'The type must be student, faculty or staff.',
            'programOrDepartment.required' => $data['type'] === 'student' ? 'The program is required.' : 'The department is required.',
        ]);

        return $validator->errors()->count()
            ? collect($validator->errors()->toArray())->map(fn ($messages) => $messages[0])->all()
            : [];
    }
}
