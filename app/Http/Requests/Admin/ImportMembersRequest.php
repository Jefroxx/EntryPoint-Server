<?php

namespace App\Http\Requests\Admin;

use App\Services\MemberImportService;
use Illuminate\Foundation\Http\FormRequest;

class ImportMembersRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // Each row is checked on its own by the import service, so a bad row is reported rather than failing the file.
            'rows'     => ['required', 'array', 'min:1', 'max:' . MemberImportService::MAX_ROWS],
            'rows.*'   => ['array'],
            'dryRun'   => ['sometimes', 'boolean'],
        ];
    }
}
