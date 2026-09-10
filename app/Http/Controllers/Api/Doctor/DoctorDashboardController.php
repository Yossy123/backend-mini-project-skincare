<?php

namespace App\Http\Controllers\Api\Doctor;

use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\Doctor;
use App\Models\Patient;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DoctorDashboardController extends Controller
{
    /**
     * Get doctor entity for the authenticated doctor user.
     */
    protected function getDoctor(Request $request): Doctor
    {
        $user = $request->user();
        $doctor = Doctor::where('user_id', $user->id)->first();

        if (! $doctor) {
            $doctor = Doctor::whereHas('user', function ($q) use ($user) {
                $q->where('email', $user->email);
            })->first();
        }

        if (! $doctor && $user->isAdmin()) {
            // Admin may inspect the doctor portal through the first active doctor's view.
            $doctor = Doctor::where('status', 'active')->orderBy('id')->first();
        }

        if (! $doctor) {
            abort(403, 'Akun ini tidak terhubung dengan profil dokter mana pun.');
        }

        return $doctor;
    }

    /**
     * Doctor Portal Overview: today's schedule, waiting list, upcoming schedule, and monthly summary.
     */
    public function overview(Request $request): JsonResponse
    {
        $doctor = $this->getDoctor($request);
        $today = Carbon::today()->format('Y-m-d');
        $startOfMonth = Carbon::now()->startOfMonth();

        // Today's appointments
        $todayAppointments = Appointment::where('doctor_id', $doctor->id)
            ->whereDate('appointment_date', $today)
            ->with(['patient', 'service'])
            ->orderBy('start_time')
            ->get();

        // Upcoming appointments (future dates)
        $upcomingAppointments = Appointment::where('doctor_id', $doctor->id)
            ->whereDate('appointment_date', '>', $today)
            ->whereNotIn('status', ['cancelled', 'no_show'])
            ->with(['patient', 'service'])
            ->orderBy('appointment_date')
            ->orderBy('start_time')
            ->limit(10)
            ->get();

        $waitingCount = $todayAppointments->where('status', 'checked_in')->count();
        $inProgressCount = $todayAppointments->where('status', 'in_progress')->count();
        $completedToday = $todayAppointments->where('status', 'completed')->count();
        $totalToday = $todayAppointments->count();

        $totalUpcoming = Appointment::where('doctor_id', $doctor->id)
            ->whereDate('appointment_date', '>', $today)
            ->whereNotIn('status', ['cancelled', 'no_show'])
            ->count();

        $monthPatientCount = Appointment::where('doctor_id', $doctor->id)
            ->where('appointment_date', '>=', $startOfMonth)
            ->where('status', 'completed')
            ->distinct('patient_id')
            ->count('patient_id');

        $recentPatients = Appointment::where('doctor_id', $doctor->id)
            ->with(['patient', 'service'])
            ->orderByDesc('appointment_date')
            ->orderByDesc('start_time')
            ->limit(5)
            ->get();

        return response()->json([
            'success' => true,
            'data' => [
                'doctor' => $doctor,
                'today_date' => $today,
                'metrics' => [
                    'total_today' => $totalToday,
                    'today_appointments' => $totalToday,
                    'waiting' => $waitingCount,
                    'waiting_patients' => $waitingCount,
                    'in_progress' => $inProgressCount,
                    'completed_today' => $completedToday,
                    'upcoming_appointments' => $totalUpcoming,
                    'monthly_patients' => $monthPatientCount,
                    'total_patients' => $monthPatientCount,
                ],
                'today_queue' => $todayAppointments,
                'today_appointments' => $todayAppointments,
                'upcoming_queue' => $upcomingAppointments,
                'upcoming_appointments' => $upcomingAppointments,
                'recent_patients' => $recentPatients,
            ],
        ]);
    }

    /**
     * Doctor's appointments list with date and status filters.
     */
    public function appointments(Request $request): JsonResponse
    {
        $doctor = $this->getDoctor($request);

        $query = Appointment::where('doctor_id', $doctor->id)
            ->with(['patient', 'service', 'statusHistories']);

        if ($request->filled('date')) {
            $query->whereDate('appointment_date', $request->date);
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
                            ->orWhere('phone', 'ILIKE', "%{$search}%");
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
     * Appointment detail scoped strictly to this doctor, plus patient's past medical notes.
     */
    public function appointmentDetail(Request $request, int $id): JsonResponse
    {
        $doctor = $this->getDoctor($request);

        $appointment = Appointment::where('id', $id)
            ->where('doctor_id', $doctor->id)
            ->with(['patient', 'service', 'statusHistories'])
            ->firstOrFail();

        // Get past appointments of this patient for medical history context
        $patientHistory = Appointment::where('patient_id', $appointment->patient_id)
            ->where('id', '!=', $appointment->id)
            ->where('status', 'completed')
            ->with(['service', 'doctor'])
            ->orderByDesc('appointment_date')
            ->get();

        return response()->json([
            'success' => true,
            'data' => [
                'appointment' => $appointment,
                'patient_history' => $patientHistory,
            ],
        ]);
    }

    /**
     * Doctor updates status (checked_in, in_progress, completed, no_show, cancelled).
     */
    public function updateStatus(Request $request, int $id): JsonResponse
    {
        $doctor = $this->getDoctor($request);

        $validated = $request->validate([
            'status' => 'required|in:checked_in,in_progress,completed,no_show,cancelled',
            'notes' => 'nullable|string|max:1000',
        ]);

        $appointment = Appointment::where('id', $id)
            ->where('doctor_id', $doctor->id)
            ->firstOrFail();

        $appointment->logStatusChange(
            $validated['status'],
            $request->user()->id,
            $validated['notes'] ?? ('Status diperbarui oleh dr. '.$doctor->name)
        );

        return response()->json([
            'success' => true,
            'message' => 'Status konsultasi berhasil diubah menjadi '.$validated['status'],
            'data' => $appointment->load(['patient', 'service', 'statusHistories']),
        ]);
    }

    /**
     * Doctor saves consultation records (diagnosis, prescription, treatment plan, doctor notes).
     */
    public function saveNotes(Request $request, int $id): JsonResponse
    {
        $doctor = $this->getDoctor($request);

        $validated = $request->validate([
            'diagnosis' => 'nullable|string',
            'doctor_notes' => 'nullable|string',
            'treatment_plan' => 'nullable|string',
            'prescription' => 'nullable|string',
            'mark_completed' => 'nullable|boolean',
        ]);

        $appointment = Appointment::where('id', $id)
            ->where('doctor_id', $doctor->id)
            ->firstOrFail();

        $appointment->update([
            'diagnosis' => $validated['diagnosis'] ?? $appointment->diagnosis,
            'doctor_notes' => $validated['doctor_notes'] ?? $appointment->doctor_notes,
            'treatment_plan' => $validated['treatment_plan'] ?? $appointment->treatment_plan,
            'prescription' => $validated['prescription'] ?? $appointment->prescription,
        ]);

        if (! empty($validated['mark_completed'])) {
            $appointment->logStatusChange('completed', $request->user()->id, 'Konsultasi dan rekam medis diselesaikan oleh dokter.');
        }

        return response()->json([
            'success' => true,
            'message' => 'Catatan medis dan diagnosis berhasil disimpan.',
            'data' => $appointment->load(['patient', 'service', 'statusHistories']),
        ]);
    }

    /**
     * Update patient allergies and general medical history.
     */
    public function updatePatientMedicalRecord(Request $request, int $patientId): JsonResponse
    {
        $doctor = $this->getDoctor($request);

        // Security check: ensure patient has at least one appointment with this doctor
        $hasRelationship = Appointment::where('patient_id', $patientId)
            ->where('doctor_id', $doctor->id)
            ->exists();

        if (! $hasRelationship && ! $request->user()->isAdmin()) {
            return response()->json([
                'success' => false,
                'message' => 'Anda tidak memiliki otorisasi untuk mengubah data pasien ini.',
            ], 403);
        }

        $validated = $request->validate([
            'allergies' => 'nullable|string',
            'medical_history' => 'nullable|string',
        ]);

        $patient = Patient::findOrFail($patientId);
        $patient->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Riwayat medis & alergi pasien berhasil diperbarui.',
            'data' => $patient,
        ]);
    }

    /**
     * List all patients who have had appointments with this doctor.
     */
    public function patients(Request $request): JsonResponse
    {
        $doctor = $this->getDoctor($request);

        $patientIds = Appointment::where('doctor_id', $doctor->id)
            ->pluck('patient_id')
            ->unique();

        $patients = Patient::whereIn('id', $patientIds)
            ->withCount(['appointments' => function ($q) use ($doctor) {
                $q->where('doctor_id', $doctor->id);
            }])
            ->orderBy('name')
            ->paginate($request->input('per_page', 15));

        return response()->json([
            'success' => true,
            'data' => $patients,
        ]);
    }
}