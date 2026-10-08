<?php

namespace App\Http\Requests;

use App\Services\Shipping\InstantCourierPolicy;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\Validator;
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
     * A pin that is not in Indonesia (a typo, or a placeholder such as 0,0) would send a courier
     * to the wrong place and price the trip wrongly.
     *
     * @return array<int, Closure(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $latitude = $this->input('latitude');
                $longitude = $this->input('longitude');

                if (is_numeric($latitude) && is_numeric($longitude)
                    && ! InstantCourierPolicy::isWithinIndonesia((float) $latitude, (float) $longitude)) {
                    $validator->errors()->add('latitude', 'Titik lokasi harus berada di wilayah Indonesia.');
                }
            },
        ];
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
            'latitude' => ['nullable', 'numeric', 'between:-90,90', 'required_with:longitude'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180', 'required_with:latitude'],
            'biteship_area_id' => ['nullable', 'string', 'max:100'],
            'address' => ['required', 'string'],
            'address_line' => ['nullable', 'string'],
            'address_detail' => ['nullable', 'string'],
            'is_default' => ['sometimes', 'boolean'],
        ];
    }
}
