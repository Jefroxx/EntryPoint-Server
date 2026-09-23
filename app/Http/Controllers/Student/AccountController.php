<?php

namespace App\Http\Controllers\Student;

use App\Http\Controllers\Controller;
use App\Http\Requests\ChangePasswordRequest;
use App\Http\Requests\UpdateStudentContactRequest;
use App\Services\SettingsService;
use App\Services\StudentPortalService;

class AccountController extends Controller
{
    public function __construct(
        private StudentPortalService $portalService,
        private SettingsService $settings,
    ) {
    }

    public function updateContact(UpdateStudentContactRequest $request)
    {
        $student = $request->user()->student;
        $this->portalService->updateContact($student, $request->validated());

        return response()->json([
            'message' => 'Details updated.',
            ...$this->portalService->profile($student->fresh()),
        ]);
    }

    /** Same rules and check as the librarians' password change (current password must match). */
    public function changePassword(ChangePasswordRequest $request)
    {
        $validated = $request->validated();
        $this->settings->changePassword($request->user(), $validated['currentPassword'], $validated['newPassword']);

        return response()->json(['message' => 'Password changed.']);
    }
}
