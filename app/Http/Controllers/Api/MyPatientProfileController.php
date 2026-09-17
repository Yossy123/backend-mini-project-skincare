<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Patient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MyPatientProfileController extends Controller
{
    /** Return only the health profile explicitly linked to the authenticated account. */
    public function show(Request $request): JsonResponse
    {
        $patient = Patient::query()
            ->where('user_id', $request->user()->id)
            ->latest('updated_at')
            ->first();

        return response()->json([
            'success' => true,
            'data' => $patient ? [
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
            ] : null,
        ]);
    }
}
