<?php

namespace App\Http\Requests\Admin;

use App\Services\AnnouncementService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveAnnouncementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'title'     => ['required', 'string', 'max:120'],
            'body'      => ['required', 'string', 'max:1000'],
            'audience'  => ['required', Rule::in(AnnouncementService::AUDIENCES)],
            'expiresAt' => ['nullable', 'date'],
        ];
    }
}
