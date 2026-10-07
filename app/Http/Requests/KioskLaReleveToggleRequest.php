<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class KioskLaReleveToggleRequest extends FormRequest
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
            'selected_date' => ['nullable', 'date'],
            'meal_rule_id' => ['required', 'integer', 'exists:meal_rules,id'],
            'is_checked' => ['required', 'boolean'],
            'notes' => ['nullable', 'string', 'max:255'],
        ];
    }
}
