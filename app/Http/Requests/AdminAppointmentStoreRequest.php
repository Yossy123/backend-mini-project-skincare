<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class AdminAppointmentStoreRequest extends FormRequest
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
     * Staff pick an existing patient, or give a name and phone for a new one.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'service_id' => ['required', 'integer', 'exists:services,id'],
            'doctor_id' => ['required', 'integer', 'exists:doctors,id'],
            'consultation_mode' => ['required', 'in:offline,online'],
            'date' => ['required', 'date_format:Y-m-d', 'after_or_equal:today'],
            'start_time' => ['required', 'date_format:H:i'],
            'patient_id' => ['nullable', 'integer', 'exists:patients,id'],
            'name' => ['required_without:patient_id', 'nullable', 'string', 'max:255'],
            'phone' => ['required_without:patient_id', 'nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:255'],
            'notes' => ['nullable', 'string', 'max:2000'],
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
            'name.required_without' => 'Nama pasien wajib diisi untuk pasien baru.',
            'phone.required_without' => 'Nomor HP wajib diisi untuk pasien baru.',
            'patient_id.exists' => 'Pasien yang dipilih tidak ditemukan.',
        ];
    }
}
