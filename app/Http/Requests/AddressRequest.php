<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class AddressRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Prepare the data for validation.
     */
    protected function prepareForValidation(): void
    {
        // Support both recipient_name / name and address_line / address
        if ($this->has('recipient_name') && ! $this->has('name')) {
            $this->merge(['name' => $this->input('recipient_name')]);
        } elseif ($this->has('name') && ! $this->has('recipient_name')) {
            $this->merge(['recipient_name' => $this->input('name')]);
        }

        if ($this->has('address_line') && ! $this->has('address')) {
            $this->merge(['address' => $this->input('address_line')]);
        } elseif ($this->has('address') && ! $this->has('address_line')) {
            $this->merge(['address_line' => $this->input('address')]);
        }
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'label' => ['nullable', 'string', 'max:50'],
            'recipient_name' => ['nullable', 'string', 'max:255'],
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['required', 'string', 'max:25'],
            'province' => ['required', 'string', 'max:255'],
            'city' => ['required', 'string', 'max:255'],
            'district' => ['required', 'string', 'max:255'],
            'postal_code' => ['required', 'string', 'max:10'],
            'biteship_area_id' => ['nullable', 'string', 'max:100'],
            'address' => ['required', 'string'],
            'address_line' => ['nullable', 'string'],
            'address_detail' => ['nullable', 'string'],
            'is_default' => ['sometimes', 'boolean'],
        ];
    }
}
