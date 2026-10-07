<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\PublicAppointmentResource;
use App\Models\Appointment;
use App\Models\Doctor;
use App\Models\Service;
use App\Services\Booking\BookingService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BookingController extends Controller
{
    public function __construct(protected BookingService $bookingService) {}

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

        if (! $this->bookingService->doctorWorksOn($doctor, $date)) {
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

        $service = $request->filled('service_id') ? Service::find($request->service_id) : null;

        return response()->json([
            'success' => true,
            'data' => [
                'doctor' => $doctor,
                'date' => $request->date,
                'is_doctor_available' => true,
                'slots' => $this->bookingService->calculateSlots($doctor, $request->date, $service),
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

        $user = $request->user('sanctum') ?? auth('sanctum')->user() ?? $request->user();

        $appointment = $this->bookingService->createBooking($validated, $user);

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
            'booking_code' => 'required|string|max:64',
        ]);

        // Codes are issued in upper case; accept them however the customer typed them.
        $bookingCode = strtoupper(trim((string) $request->booking_code));

        $appointment = Appointment::where('booking_code', $bookingCode)
            ->with(['patient', 'doctor', 'service', 'statusHistories'])
            ->firstOrFail();

        return response()->json([
            'success' => true,
            'data' => new PublicAppointmentResource($appointment),
        ]);
    }
}
