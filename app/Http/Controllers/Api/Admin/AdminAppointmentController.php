<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\Service;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminAppointmentController extends Controller
{
    /**
     * List all appointments with filtering & pagination.
     */
    public function index(Request $request): JsonResponse
    {
        $query = Appointment::with(['patient', 'doctor', 'service', 'statusHistories']);

        if ($request->filled('date')) {
            $query->where('appointment_date', $request->date);
        }

        if ($request->filled('doctor_id')) {
            $query->where('doctor_id', $request->doctor_id);
        }

        if ($request->filled('service_id')) {
            $query->where('service_id', $request->service_id);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('booking_code', 'ILIKE', "%{$search}%")
                    ->orWhereHas('patient', function ($pq) use ($search) {
                        $pq->where('name', 'ILIKE', "%{$search}%")
                            ->orWhere('phone', 'ILIKE', "%{$search}%")
                            ->orWhere('email', 'ILIKE', "%{$search}%");
                    });
            });
        }

        $appointments = $query->orderByDesc('appointment_date')
            ->orderByDesc('start_time')
            ->paginate($request->input('per_page', 15));

        return response()->json([
            'success' => true,
            'data' => $appointments,
        ]);
    }

    /**
     * Show single appointment with full dossier.
     */
    public function show(int $id): JsonResponse
    {
        $appointment = Appointment::with(['patient', 'doctor', 'service', 'statusHistories.changer'])
            ->findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => $appointment,
        ]);
    }

    /**
     * Update appointment status.
     */
    public function updateStatus(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'status' => 'required|in:pending,confirmed,checked_in,in_progress,completed,cancelled,no_show',
            'notes' => 'nullable|string|max:1000',
            'cancellation_reason' => 'nullable|string|max:1000',
        ]);

        $appointment = Appointment::findOrFail($id);

        if ($validated['status'] === 'cancelled' && ! empty($validated['cancellation_reason'])) {
            $appointment->cancellation_reason = $validated['cancellation_reason'];
        }

        $appointment->logStatusChange(
            $validated['status'],
            $request->user()->id,
            $validated['notes'] ?? ('Status diubah menjadi '.$validated['status'].' oleh admin')
        );

        return response()->json([
            'success' => true,
            'message' => 'Status appointment berhasil diperbarui menjadi '.$validated['status'],
            'data' => $appointment->load(['patient', 'doctor', 'service', 'statusHistories']),
        ]);
    }

    /**
     * Reschedule or update appointment details.
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $appointment = Appointment::findOrFail($id);

        $validated = $request->validate([
            'doctor_id' => 'nullable|exists:doctors,id',
            'service_id' => 'nullable|exists:services,id',
            'appointment_date' => 'nullable|date_format:Y-m-d',
            'start_time' => 'nullable|date_format:H:i',
            'consultation_mode' => 'nullable|in:offline,online',
            'doctor_notes' => 'nullable|string',
            'diagnosis' => 'nullable|string',
            'treatment_plan' => 'nullable|string',
            'prescription' => 'nullable|string',
        ]);

        if (! empty($validated['doctor_id'])) {
            $appointment->doctor_id = $validated['doctor_id'];
        }

        if (! empty($validated['service_id'])) {
            $appointment->service_id = $validated['service_id'];
        }

        if (! empty($validated['appointment_date'])) {
            $appointment->appointment_date = $validated['appointment_date'];
        }

        if (! empty($validated['start_time'])) {
            $service = Service::find($appointment->service_id);
            $duration = $service ? $service->duration_minutes : 60;

            $startTime = Carbon::parse($appointment->appointment_date.' '.$validated['start_time'].':00');
            $endTime = $startTime->copy()->addMinutes($duration);

            $appointment->start_time = $startTime->format('H:i:s');
            $appointment->end_time = $endTime->format('H:i:s');
        }

        if (isset($validated['consultation_mode'])) {
            $appointment->consultation_mode = $validated['consultation_mode'];
        }
        if (isset($validated['doctor_notes'])) {
            $appointment->doctor_notes = $validated['doctor_notes'];
        }
        if (isset($validated['diagnosis'])) {
            $appointment->diagnosis = $validated['diagnosis'];
        }
        if (isset($validated['treatment_plan'])) {
            $appointment->treatment_plan = $validated['treatment_plan'];
        }
        if (isset($validated['prescription'])) {
            $appointment->prescription = $validated['prescription'];
        }

        $appointment->save();

        return response()->json([
            'success' => true,
            'message' => 'Detail appointment berhasil diperbarui.',
            'data' => $appointment->load(['patient', 'doctor', 'service', 'statusHistories']),
        ]);
    }

    /**
     * Delete appointment.
     */
    public function destroy(int $id): JsonResponse
    {
        $appointment = Appointment::findOrFail($id);
        $appointment->delete();

        return response()->json([
            'success' => true,
            'message' => 'Appointment berhasil dihapus.',
        ]);
    }
}
