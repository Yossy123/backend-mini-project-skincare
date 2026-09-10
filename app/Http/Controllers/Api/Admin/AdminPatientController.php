<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Patient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminPatientController extends Controller
{
    /**
     * List all patients with search & stats.
     */
    public function index(Request $request): JsonResponse
    {
        $query = Patient::withCount('appointments');

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'ILIKE', "%{$search}%")
                    ->orWhere('phone', 'ILIKE', "%{$search}%")
                    ->orWhere('email', 'ILIKE', "%{$search}%");
            });
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        $patients = $query->orderByDesc('created_at')
            ->paginate($request->input('per_page', 15));

        return response()->json([
            'success' => true,
            'data' => $patients,
        ]);
    }

    /**
     * Show full patient medical dossier and appointment history.
     */
    public function show(int $id): JsonResponse
    {
        $patient = Patient::with([
            'user',
            'appointments' => function ($q) {
                $q->with(['doctor', 'service', 'statusHistories'])->orderByDesc('appointment_date')->orderByDesc('start_time');
            },
        ])->findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => $patient,
        ]);
    }

    /**
     * Update patient medical records & contact details.
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $patient = Patient::findOrFail($id);

        $validated = $request->validate([
            'name' => 'nullable|string|max:255',
            'phone' => 'nullable|string|max:30',
            'email' => 'nullable|email|max:255',
            'date_of_birth' => 'nullable|date',
            'gender' => 'nullable|string|in:male,female,other',
            'address' => 'nullable|string',
            'allergies' => 'nullable|string',
            'medical_history' => 'nullable|string',
            'emergency_contact' => 'nullable|string',
            'status' => 'nullable|string|in:active,inactive',
        ]);

        $patient->update(array_filter($validated, fn ($val) => ! is_null($val)));

        return response()->json([
            'success' => true,
            'message' => 'Data pasien berhasil diperbarui.',
            'data' => $patient,
        ]);
    }
}
