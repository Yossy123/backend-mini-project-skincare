<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateMyHealthProfileRequest;
use App\Models\Patient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class MyPatientProfileController extends Controller
{
    /** Return only the health profile explicitly linked to the authenticated account. */
    public function show(Request $request): JsonResponse
    {
        $patient = $this->linkedPatient($request);

        return response()->json([
            'success' => true,
            'data' => $patient ? $this->present($patient) : null,
        ]);
    }

    /**
     * Let the customer edit the medical details of their own profile.
     *
     * An account with no linked patient record gets one created from its own name, phone and email.
     * An existing record is never claimed by phone number, so nobody else's data is reachable here.
     */
    public function update(UpdateMyHealthProfileRequest $request): JsonResponse
    {
        $user = $request->user();
        $patient = $this->linkedPatient($request);

        if (! $patient) {
            if (blank($user->phone)) {
                throw ValidationException::withMessages([
                    'phone' => ['Lengkapi nomor telepon akunmu terlebih dahulu sebelum mengisi profil kesehatan.'],
                ]);
            }

            $patient = new Patient([
                'user_id' => $user->id,
                'name' => $user->name,
                'phone' => $user->phone,
                'email' => $user->email,
            ]);
        }

        $patient->fill($request->validated())->save();

        return response()->json(['success' => true, 'data' => $this->present($patient->refresh())]);
    }

    private function linkedPatient(Request $request): ?Patient
    {
        return Patient::query()
            ->where('user_id', $request->user()->id)
            ->latest('updated_at')
            ->first();
    }

    /** @return array<string, mixed> */
    private function present(Patient $patient): array
    {
        return [
            'id' => $patient->id,
            'name' => $patient->name,
            'phone' => $patient->phone,
            'email' => $patient->email,
            'date_of_birth' => $patient->date_of_birth?->format('Y-m-d'),
            'gender' => $patient->gender,
            'address' => $patient->address,
            'allergies' => $patient->allergies,
            'medical_history' => $patient->medical_history,
            'emergency_contact' => $patient->emergency_contact,
        ];
    }
}
