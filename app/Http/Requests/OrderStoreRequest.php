<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class OrderStoreRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:99'],
            'address_id' => ['required', 'integer', 'exists:addresses,id'],
            'courier' => ['required', 'string', 'max:50'],
            'service' => ['required', 'string', 'max:50'],
        ];
    }

    /**
     * Custom validation messages.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'items.required' => 'At least one product is required to place an order.',
            'items.min' => 'At least one product is required to place an order.',
            'address_id.required' => 'A delivery address is required.',
            'address_id.exists' => 'The selected delivery address does not exist.',
            'courier.required' => 'Please select a shipping courier.',
            'service.required' => 'Please select a courier shipping service.',
        ];
    }
}
