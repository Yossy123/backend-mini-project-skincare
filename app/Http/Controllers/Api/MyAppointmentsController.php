<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\CustomerAppointmentResource;
use App\Models\Appointment;
use App\Models\Patient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MyAppointmentsController extends Controller
{
    /**
     * Get all appointments belonging to current authenticated customer.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $patientIds = Patient::where('user_id', $user->id)->pluck('id');

        $appointments = Appointment::whereIn('patient_id', $patientIds)
            ->orWhere('created_by', $user->id)
            ->with(['doctor', 'service', 'patient'])
            ->orderByDesc('appointment_date')
            ->orderByDesc('start_time')
            ->paginate(15);

        return response()->json([
            'success' => true,
            'data' => CustomerAppointmentResource::collection($appointments),
        ]);
    }

    /**
     * Show single appointment detail if owned by user.
     */
    public function show(Request $request, int $id): JsonResponse
    {
        $user = $request->user();

        $patientIds = Patient::where('user_id', $user->id)->pluck('id');

        $appointment = Appointment::where('id', $id)
            ->where(function ($q) use ($patientIds, $user) {
                $q->whereIn('patient_id', $patientIds)
                    ->orWhere('created_by', $user->id);
            })
            ->with(['doctor', 'service', 'patient', 'statusHistories'])
            ->firstOrFail();

        return response()->json([
            'success' => true,
            'data' => new CustomerAppointmentResource($appointment),
        ]);
    }

    /**
     * Cancel an appointment if pending or confirmed.
     */
    public function cancel(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'reason' => 'nullable|string|max:500',
        ]);

        $user = $request->user();

        $patientIds = Patient::where('user_id', $user->id)->pluck('id');

        $appointment = Appointment::where('id', $id)
            ->where(function ($q) use ($patientIds, $user) {
                $q->whereIn('patient_id', $patientIds)
                    ->orWhere('created_by', $user->id);
            })
            ->firstOrFail();

        if (in_array($appointment->status, ['completed', 'cancelled', 'in_progress'])) {
            return response()->json([
                'success' => false,
                'message' => 'Appointment dengan status '.$appointment->status.' tidak dapat dibatalkan.',
            ], 422);
        }

        $appointment->cancellation_reason = $request->input('reason', 'Dibatalkan oleh pasien');
        $appointment->logStatusChange('cancelled', $user->id, 'Pembatalan oleh pasien: '.$appointment->cancellation_reason);

        return response()->json([
            'success' => true,
            'message' => 'Reservasi berhasil dibatalkan.',
            'data' => new CustomerAppointmentResource($appointment->load(['doctor', 'service', 'patient'])),
        ]);
    }
}
