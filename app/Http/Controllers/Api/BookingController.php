<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\PublicAppointmentResource;
use App\Models\Appointment;
use App\Models\Doctor;
use App\Models\Patient;
use App\Models\Service;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class BookingController extends Controller
{
    /**
     * Get list of active services for booking.
     */
    public function getServices(): JsonResponse
    {
        $services = Service::where('is_active', true)
            ->orderBy('price')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $services,
        ]);
    }

    /**
     * Get list of active doctors.
     */
    public function getDoctors(): JsonResponse
    {
        $doctors = Doctor::where('status', 'active')
            ->orderBy('name')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $doctors,
        ]);
    }

    /**
     * Calculate available booking time slots for a given doctor, date, and service.
     */
    public function getAvailableSlots(Request $request): JsonResponse
    {
        $request->validate([
            'doctor_id' => 'required|exists:doctors,id',
            'date' => 'required|date_format:Y-m-d|after_or_equal:today',
            'service_id' => 'nullable|exists:services,id',
        ]);

        $doctor = Doctor::findOrFail($request->doctor_id);

        if (! $doctor->isActive()) {
            return response()->json([
                'success' => false,
                'message' => 'Dokter sedang tidak aktif.',
                'data' => [],
            ], 422);
        }

        $date = Carbon::parse($request->date);
        $dayOfWeek = $date->dayOfWeek; // 0 (Sun) to 6 (Sat)

        // Validate doctor's schedule days
        $availableDays = $doctor->available_days ?? [1, 2, 3, 4, 5];
        if (! in_array($dayOfWeek, $availableDays)) {
            return response()->json([
                'success' => true,
                'message' => 'Dokter tidak berpraktik pada hari '.$date->locale('id')->isoFormat('dddd'),
                'data' => [
                    'doctor' => $doctor,
                    'date' => $request->date,
                    'is_doctor_available' => false,
                    'slots' => [],
                ],
            ]);
        }

        $durationMinutes = 60;
        if ($request->filled('service_id')) {
            $service = Service::find($request->service_id);
            if ($service) {
                $durationMinutes = $service->duration_minutes;
            }
        }

        $workStart = Carbon::parse($request->date.' '.($doctor->work_start_time ?? '09:00:00'));
        $workEnd = Carbon::parse($request->date.' '.($doctor->work_end_time ?? '17:00:00'));

        // Query existing active bookings for this doctor on the date
        $bookedAppointments = Appointment::where('doctor_id', $doctor->id)
            ->whereDate('appointment_date', $request->date)
            ->whereNotIn('status', ['cancelled', 'no_show'])
            ->get(['start_time', 'end_time']);

        $slots = [];
        $current = $workStart->copy();
        $isToday = $date->isToday();
        $now = Carbon::now();

        while ($current->copy()->addMinutes($durationMinutes)->lte($workEnd)) {
            $slotStart = $current->copy();
            $slotEnd = $current->copy()->addMinutes($durationMinutes);

            $startStr = $slotStart->format('H:i');
            $endStr = $slotEnd->format('H:i');

            // Check overlap with existing appointments
            $isBooked = false;
            foreach ($bookedAppointments as $appt) {
                $apptStart = Carbon::parse($request->date.' '.$appt->start_time);
                $apptEnd = Carbon::parse($request->date.' '.$appt->end_time);

                // Check overlap: start < apptEnd && end > apptStart
                if ($slotStart->lt($apptEnd) && $slotEnd->gt($apptStart)) {
                    $isBooked = true;
                    break;
                }
            }

            // If date is today and slot is in the past, mark as booked / unavailable
            if ($isToday && $slotStart->lte($now)) {
                $isBooked = true;
            }

            $slots[] = [
                'start' => $startStr,
                'end' => $endStr,
                'is_booked' => $isBooked,
            ];

            $current->addMinutes($durationMinutes);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'doctor' => $doctor,
                'date' => $request->date,
                'is_doctor_available' => true,
                'slots' => $slots,
            ],
        ]);
    }

    /**
     * Create a new booking transactionally with pessimistic locking against collisions.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'service_id' => 'required|exists:services,id',
            'doctor_id' => 'required|exists:doctors,id',
            'consultation_mode' => 'required|in:offline,online',
            'date' => 'required|date_format:Y-m-d|after_or_equal:today',
            'start_time' => 'required|date_format:H:i',
            'name' => 'required|string|max:255',
            'phone' => 'required|string|max:30',
            'email' => 'nullable|email|max:255',
            'notes' => 'nullable|string|max:2000',
            // Base64 adds overhead; cap the request before decoding it.
            'photo_url' => 'nullable|string|max:7168000',
        ]);

        // Process Base64 image upload if provided
        if (! empty($validated['photo_url'])) {
            if (preg_match('/^data:image\/(jpeg|jpg|png|webp);base64,/', $validated['photo_url'], $matches)) {
                $imageType = strtolower($matches[1]);
                $imageData = substr($validated['photo_url'], strpos($validated['photo_url'], ',') + 1);
                $decodedData = base64_decode($imageData, true);
                $imageInfo = $decodedData !== false ? @getimagesizefromstring($decodedData) : false;

                if ($decodedData === false || strlen($decodedData) > 5 * 1024 * 1024 || $imageInfo === false) {
                    throw ValidationException::withMessages([
                        'photo_url' => ['Foto tidak valid atau ukurannya melebihi 5 MB.'],
                    ]);
                }

                $allowedMimeTypes = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
                $extension = $allowedMimeTypes[$imageInfo['mime'] ?? ''] ?? null;
                if ($extension === null || ($imageInfo[0] ?? 0) > 6000 || ($imageInfo[1] ?? 0) > 6000) {
                    throw ValidationException::withMessages([
                        'photo_url' => ['Format foto harus JPEG, PNG, atau WebP dengan ukuran maksimal 6000x6000 piksel.'],
                    ]);
                }

                $filename = 'bookings/' . Str::random(30) . '.' . $extension;
                Storage::disk('public')->put($filename, $decodedData);
                $validated['photo_url'] = '/storage/' . $filename;
            } else {
                throw ValidationException::withMessages([
                    'photo_url' => ['Foto harus dikirim dalam format JPEG, PNG, atau WebP yang valid.'],
                ]);
            }
        }

        $appointment = DB::transaction(function () use ($validated, $request) {
            // 1. Lock doctor & verify active status
            $doctor = Doctor::where('id', $validated['doctor_id'])
                ->lockForUpdate()
                ->firstOrFail();

            if (! $doctor->isActive()) {
                throw ValidationException::withMessages([
                    'doctor_id' => ['Dokter yang dipilih sedang tidak aktif.'],
                ]);
            }

            // 2. Verify service
            $service = Service::where('id', $validated['service_id'])
                ->where('is_active', true)
                ->firstOrFail();

            // 3. Verify schedule day
            $date = Carbon::parse($validated['date']);
            $dayOfWeek = $date->dayOfWeek;
            $availableDays = $doctor->available_days ?? [1, 2, 3, 4, 5];

            if (! in_array($dayOfWeek, $availableDays)) {
                throw ValidationException::withMessages([
                    'date' => ['Dokter tidak berpraktik pada hari '.$date->locale('id')->isoFormat('dddd')],
                ]);
            }

            // 4. Calculate start and end time
            $startTime = Carbon::parse($validated['date'].' '.$validated['start_time'].':00');
            $endTime = $startTime->copy()->addMinutes($service->duration_minutes);

            $workStart = Carbon::parse($validated['date'].' '.($doctor->work_start_time ?? '09:00:00'));
            $workEnd = Carbon::parse($validated['date'].' '.($doctor->work_end_time ?? '17:00:00'));

            if ($startTime->lt($workStart) || $endTime->gt($workEnd)) {
                throw ValidationException::withMessages([
                    'start_time' => ['Jam yang dipilih berada di luar jam operasional praktik dokter ('.$doctor->work_start_time.' - '.$doctor->work_end_time.').'],
                ]);
            }

            // Prevent booking in the past for today
            if ($date->isToday() && $startTime->lte(Carbon::now())) {
                throw ValidationException::withMessages([
                    'start_time' => ['Jam yang dipilih sudah terlewat untuk hari ini.'],
                ]);
            }

            // 5. Pessimistic check for overlapping appointments
            $startTimeStr = $startTime->format('H:i:s');
            $endTimeStr = $endTime->format('H:i:s');

            $collision = Appointment::where('doctor_id', $doctor->id)
                ->whereDate('appointment_date', $validated['date'])
                ->whereNotIn('status', ['cancelled', 'no_show'])
                ->where(function ($query) use ($startTimeStr, $endTimeStr) {
                    $query->where(function ($q) use ($startTimeStr, $endTimeStr) {
                        $q->where('start_time', '<', $endTimeStr)
                            ->where('end_time', '>', $startTimeStr);
                    });
                })
                ->lockForUpdate()
                ->exists();

            if ($collision) {
                throw ValidationException::withMessages([
                    'start_time' => ['Slot jadwal jam ini sudah diambil oleh pasien lain. Silakan pilih jam atau dokter lain.'],
                ]);
            }

            // 6. Find or Create Patient and link user if authenticated
            $user = $request->user('sanctum') ?? auth('sanctum')->user() ?? $request->user();
            $userId = $user ? $user->id : null;

            $patient = Patient::firstOrNew(['phone' => $validated['phone']]);
            $patient->name = $validated['name'];
            if (! empty($validated['email'])) {
                $patient->email = $validated['email'];
            }
            if ($userId && ! $patient->user_id) {
                $patient->user_id = $userId;
            }
            $patient->save();

            // 7. Generate Unique Booking Code
            do {
                $bookingCode = 'LMR-BKG-'.Carbon::parse($validated['date'])->format('Ymd').'-'.strtoupper(Str::random(4));
            } while (Appointment::where('booking_code', $bookingCode)->exists());

            // 8. Create Appointment
            $appointment = Appointment::create([
                'booking_code' => $bookingCode,
                'patient_id' => $patient->id,
                'doctor_id' => $doctor->id,
                'service_id' => $service->id,
                'appointment_date' => $validated['date'],
                'start_time' => $startTimeStr,
                'end_time' => $endTimeStr,
                'consultation_mode' => $validated['consultation_mode'],
                'complaint' => $validated['notes'] ?? null,
                'patient_notes' => $validated['notes'] ?? null,
                'photo_url' => $validated['photo_url'] ?? null,
                'status' => 'confirmed',
                'created_by' => $userId,
            ]);

            // 9. Log Initial Status History
            $appointment->logStatusChange('confirmed', $userId, 'Reservasi baru dibuat melalui sistem booking klinik.');

            return $appointment->load(['patient', 'doctor', 'service']);
        });

        return response()->json([
            'success' => true,
            'message' => 'Reservasi perawatan berhasil dikonfirmasi!',
            'data' => $appointment,
        ], 201);
    }

    /**
     * Lookup appointment by booking_code for public guest confirmation.
     */
    public function lookup(Request $request): JsonResponse
    {
        $request->validate([
            'booking_code' => 'required|string',
        ]);

        $appointment = Appointment::where('booking_code', $request->booking_code)
            ->with(['patient', 'doctor', 'service', 'statusHistories'])
            ->firstOrFail();

        return response()->json([
            'success' => true,
            'data' => new PublicAppointmentResource($appointment),
        ]);
    }
}
