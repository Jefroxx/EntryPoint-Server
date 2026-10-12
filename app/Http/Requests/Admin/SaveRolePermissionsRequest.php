<?php

namespace App\Http\Requests\Admin;

use App\Services\StaffPermissionService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveRolePermissionsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'permissions'   => ['present', 'array'],
            'permissions.*' => ['string', Rule::in(app(StaffPermissionService::class)->keys())],
        ];
    }
}
