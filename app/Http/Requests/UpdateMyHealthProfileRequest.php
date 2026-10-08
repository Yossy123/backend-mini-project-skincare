<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateMyHealthProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Name, phone and email belong to the account and are changed through `PATCH /me`.
     *
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'date_of_birth' => ['nullable', 'date', 'before_or_equal:today', 'after:1900-01-01'],
            'gender' => ['nullable', 'string', 'in:male,female,other'],
            'address' => ['nullable', 'string', 'max:500'],
            'allergies' => ['nullable', 'string', 'max:2000'],
            'medical_history' => ['nullable', 'string', 'max:5000'],
            'emergency_contact' => ['nullable', 'string', 'max:255'],
        ];
    }
}
