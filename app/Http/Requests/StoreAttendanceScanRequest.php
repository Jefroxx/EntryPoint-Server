<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreAttendanceScanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** Scanners often append a line break or tab to the code; strip them before validating. */
    protected function prepareForValidation(): void
    {
        $this->merge([
            'barcodeValue' => preg_replace('/[\r\n\t]+/', '', trim((string) $this->input('barcodeValue'))),
        ]);
    }

    public function rules(): array
    {
        return [
            'barcodeValue' => ['required', 'string', 'max:64'],
        ];
    }
}
