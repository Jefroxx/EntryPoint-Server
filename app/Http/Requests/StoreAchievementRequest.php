<?php

namespace App\Http\Requests;

use App\Models\Achievement;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAchievementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name'                   => ['required', 'string', 'max:150'],
            'criteriaJSON'           => ['required', 'array'],
            'criteriaJSON.metric'    => ['required', Rule::in(Achievement::CRITERIA_METRICS)],
            'criteriaJSON.threshold' => ['required', 'integer', 'min:1'],
            'pointsReward'           => ['required', 'integer', 'min:0'],
        ];
    }
}
