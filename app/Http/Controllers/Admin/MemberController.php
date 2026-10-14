<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ImportMembersRequest;
use App\Http\Requests\Admin\StoreMemberRequest;
use App\Http\Requests\Admin\UpdateMemberRequest;
use App\Models\Student;
use App\Services\AdminAccountService;
use App\Services\MemberImportService;
use Illuminate\Http\Request;

/** Faculty and staff members. (Students register themselves and are approved on the librarian side.) */
class MemberController extends Controller
{
    public function __construct(private AdminAccountService $accounts)
    {
    }

    public function index(Request $request)
    {
        return response()->json($this->accounts->members(
            $request->query('search'),
            $request->query('type'),
            (int) ($request->query('perPage') ?? 15),
        ));
    }

    /**
     * Create many accounts from a list. With dryRun the rows are only checked: the answer says which would be created
     * and which skipped (and why), so the screen can show it before anything is made.
     */
    public function import(ImportMembersRequest $request, MemberImportService $import)
    {
        $rows = $request->validated('rows');

        if ($request->boolean('dryRun')) {
            return response()->json($import->check($rows));
        }

        return response()->json($import->import($request->user(), $rows), 201);
    }

    public function store(StoreMemberRequest $request)
    {
        return response()->json($this->accounts->createMember($request->user(), $request->validated()), 201);
    }

    public function update(UpdateMemberRequest $request, Student $student)
    {
        $this->ensureNotAStudent($student);

        return response()->json(['account' => $this->accounts->updateMember($request->user(), $student, $request->validated())]);
    }

    public function disable(Request $request, Student $student)
    {
        $this->accounts->setDisabled($request->user(), $student->user, true);

        return response()->json(['message' => 'Account disabled.']);
    }

    public function enable(Request $request, Student $student)
    {
        $this->accounts->setDisabled($request->user(), $student->user, false);

        return response()->json(['message' => 'Account enabled.']);
    }

    public function resetPassword(Request $request, Student $student)
    {
        return response()->json(['temporaryPassword' => $this->accounts->resetPassword($request->user(), $student->user)]);
    }

    /** Editing here is for faculty and staff; a student's record belongs to the Students page. */
    private function ensureNotAStudent(Student $student): void
    {
        abort_if($student->memberType === 'student', 404);
    }
}
