<?php

namespace App\Http\Requests\Admin;

use App\Services\StaffPermissionService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Every role changed on the roles screen, saved together: { roles: { librarian: [...], assistant: [...] } }.
 * Only the roles that can be changed are accepted (never the administrator), each with its full list of permissions.
 */
class SaveRolesRequest extends FormRequest
{
    public const EDITABLE = ['librarian', 'assistant'];

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'roles'     => ['required', 'array', 'min:1'],
            'roles.*'   => ['present', 'array'],
            'roles.*.*' => ['string', Rule::in(app(StaffPermissionService::class)->keys())],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            $unknown = array_diff(array_keys((array) $this->input('roles', [])), self::EDITABLE);

            if ($unknown) {
                $validator->errors()->add('roles', 'Only the librarian and student assistant roles can be changed.');
            }
        }];
    }
}
