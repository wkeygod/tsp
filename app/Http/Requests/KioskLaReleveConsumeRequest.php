<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class KioskLaReleveConsumeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'entry_id' => ['required', 'integer', 'exists:la_releve_entries,id'],
            'meal_rule_id' => ['required', 'integer', 'exists:meal_rules,id'],
            'selected_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:255'],
        ];
    }
}
