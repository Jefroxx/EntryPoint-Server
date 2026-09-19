<?php

namespace App\Http\Requests;

use App\Models\Achievement;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAchievementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name'                   => ['sometimes', 'string', 'max:150'],
            'criteriaJSON'           => ['sometimes', 'array'],
            'criteriaJSON.metric'    => ['sometimes', Rule::in(Achievement::CRITERIA_METRICS)],
            'criteriaJSON.threshold' => ['sometimes', 'integer', 'min:1'],
            'pointsReward'           => ['sometimes', 'integer', 'min:0'],
        ];
    }
}
