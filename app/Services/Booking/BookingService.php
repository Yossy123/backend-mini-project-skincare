<?php

namespace App\Services\Booking;

use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\Doctor;
use App\Models\Patient;
use App\Models\Service;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class BookingService
{
    private const DEFAULT_AVAILABLE_DAYS = [1, 2, 3, 4, 5];

    private const DEFAULT_WORK_START = '09:00:00';

    private const DEFAULT_WORK_END = '17:00:00';

    private const DEFAULT_SLOT_MINUTES = 60;

    public function doctorWorksOn(Doctor $doctor, Carbon $date): bool
    {
        return in_array($date->dayOfWeek, $doctor->available_days ?? self::DEFAULT_AVAILABLE_DAYS);
    }

    /**
     * Build the slot grid for one doctor on one date, flagging slots that are taken or already past.
     *
     * @return array<int, array{start: string, end: string, is_booked: bool}>
     */
    public function calculateSlots(Doctor $doctor, string $date, ?Service $service = null): array
    {
        $day = Carbon::parse($date);
        $durationMinutes = $service?->duration_minutes ?? self::DEFAULT_SLOT_MINUTES;

        $workStart = Carbon::parse($date.' '.($doctor->work_start_time ?? self::DEFAULT_WORK_START));
        $workEnd = Carbon::parse($date.' '.($doctor->work_end_time ?? self::DEFAULT_WORK_END));

        $bookedAppointments = Appointment::where('doctor_id', $doctor->id)
            ->whereDate('appointment_date', $date)
            ->whereNotIn('status', AppointmentStatus::releasedValues())
            ->get(['start_time', 'end_time']);

        $slots = [];
        $current = $workStart->copy();
        $isToday = $day->isToday();
        $now = Carbon::now();

        while ($current->copy()->addMinutes($durationMinutes)->lte($workEnd)) {
            $slotStart = $current->copy();
            $slotEnd = $current->copy()->addMinutes($durationMinutes);

            $isBooked = false;
            foreach ($bookedAppointments as $appointment) {
                $appointmentStart = Carbon::parse($date.' '.$appointment->start_time);
                $appointmentEnd = Carbon::parse($date.' '.$appointment->end_time);

                if ($slotStart->lt($appointmentEnd) && $slotEnd->gt($appointmentStart)) {
                    $isBooked = true;
                    break;
                }
            }

            // Slots already in the past are unavailable for today.
            if ($isToday && $slotStart->lte($now)) {
                $isBooked = true;
            }

            $slots[] = [
                'start' => $slotStart->format('H:i'),
                'end' => $slotEnd->format('H:i'),
                'is_booked' => $isBooked,
            ];

            $current->addMinutes($durationMinutes);
        }

        return $slots;
    }

    /**
     * Decode and store an uploaded base64 photo on the private disk, returning its disk path.
     *
     * Clinical photos are never placed on the public disk; they are only reachable through
     * short-lived signed URLs (see Appointment::photoUrl()).
     *
     * @throws ValidationException
     */
    public function storeBase64Photo(string $dataUri): string
    {
        if (! preg_match('/^data:image\/(jpeg|jpg|png|webp);base64,/', $dataUri)) {
            throw ValidationException::withMessages([
                'photo_url' => ['Foto harus dikirim dalam format JPEG, PNG, atau WebP yang valid.'],
            ]);
        }

        $imageData = substr($dataUri, strpos($dataUri, ',') + 1);
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

        $filename = Appointment::PHOTO_DIRECTORY.'/'.Str::random(40).'.'.$extension;
        Storage::disk(Appointment::PHOTO_DISK)->put($filename, $decodedData);

        return $filename;
    }

    /**
     * Create a booking transactionally, locking the doctor row against double-booking.
     *
     * @param  array<string, mixed>  $data  Validated booking payload.
     *
     * @throws ValidationException
     */
    public function createBooking(array $data, ?User $user): Appointment
    {
        $storedPhoto = null;
        if (! empty($data['photo_url'])) {
            $storedPhoto = $data['photo_url'] = $this->storeBase64Photo($data['photo_url']);
        }

        try {
            return $this->createBookingRecord($data, $user);
        } catch (\Throwable $exception) {
            if ($storedPhoto !== null) {
                Storage::disk(Appointment::PHOTO_DISK)->delete($storedPhoto);
            }

            throw $exception;
        }
    }

    /**
     * @param  array<string, mixed>  $data  Validated booking payload with the photo already stored.
     *
     * @throws ValidationException
     */
    private function createBookingRecord(array $data, ?User $user): Appointment
    {
        return DB::transaction(function () use ($data, $user) {
            $doctor = Doctor::where('id', $data['doctor_id'])
                ->lockForUpdate()
                ->firstOrFail();

            if (! $doctor->isActive()) {
                throw ValidationException::withMessages([
                    'doctor_id' => ['Dokter yang dipilih sedang tidak aktif.'],
                ]);
            }

            $service = Service::where('id', $data['service_id'])
                ->where('is_active', true)
                ->firstOrFail();

            $date = Carbon::parse($data['date']);

            if (! $this->doctorWorksOn($doctor, $date)) {
                throw ValidationException::withMessages([
                    'date' => ['Dokter tidak berpraktik pada hari '.$date->locale('id')->isoFormat('dddd')],
                ]);
            }

            $startTime = Carbon::parse($data['date'].' '.$data['start_time'].':00');
            $endTime = $startTime->copy()->addMinutes($service->duration_minutes);

            $workStart = Carbon::parse($data['date'].' '.($doctor->work_start_time ?? self::DEFAULT_WORK_START));
            $workEnd = Carbon::parse($data['date'].' '.($doctor->work_end_time ?? self::DEFAULT_WORK_END));

            if ($startTime->lt($workStart) || $endTime->gt($workEnd)) {
                throw ValidationException::withMessages([
                    'start_time' => ['Jam yang dipilih berada di luar jam operasional praktik dokter ('.$doctor->work_start_time.' - '.$doctor->work_end_time.').'],
                ]);
            }

            if ($date->isToday() && $startTime->lte(Carbon::now())) {
                throw ValidationException::withMessages([
                    'start_time' => ['Jam yang dipilih sudah terlewat untuk hari ini.'],
                ]);
            }

            $startTimeStr = $startTime->format('H:i:s');
            $endTimeStr = $endTime->format('H:i:s');

            $collision = Appointment::where('doctor_id', $doctor->id)
                ->whereDate('appointment_date', $data['date'])
                ->whereNotIn('status', AppointmentStatus::releasedValues())
                ->where('start_time', '<', $endTimeStr)
                ->where('end_time', '>', $startTimeStr)
                ->lockForUpdate()
                ->exists();

            if ($collision) {
                throw ValidationException::withMessages([
                    'start_time' => ['Slot jadwal jam ini sudah diambil oleh pasien lain. Silakan pilih jam atau dokter lain.'],
                ]);
            }

            $patient = $this->resolvePatient($data, $user);

            $appointment = Appointment::create([
                'booking_code' => $this->generateBookingCode($date),
                'patient_id' => $patient->id,
                'doctor_id' => $doctor->id,
                'service_id' => $service->id,
                'appointment_date' => $data['date'],
                'start_time' => $startTimeStr,
                'end_time' => $endTimeStr,
                'consultation_mode' => $data['consultation_mode'],
                'complaint' => $data['notes'] ?? null,
                'patient_notes' => $data['notes'] ?? null,
                'photo_url' => $data['photo_url'] ?? null,
                'status' => AppointmentStatus::Confirmed->value,
                'created_by' => $user?->id,
            ]);

            $appointment->logStatusChange(AppointmentStatus::Confirmed->value, $user?->id, 'Reservasi baru dibuat melalui sistem booking klinik.');

            return $appointment->load(['patient', 'doctor', 'service']);
        });
    }

    /**
     * Reuse only a patient record already owned by the booking account.
     *
     * A phone number is user-supplied and unverified, so it must never be used to
     * claim or overwrite another person's clinical record; records booked by
     * someone else (or walk-in records without an account) stay untouched and a
     * separate record is created for this account instead.
     *
     * @param  array<string, mixed>  $data
     */
    private function resolvePatient(array $data, ?User $user): Patient
    {
        $patient = $user
            ? Patient::firstOrNew(['user_id' => $user->id, 'phone' => $data['phone']])
            : new Patient(['phone' => $data['phone']]);

        $patient->name = $data['name'];

        if (! empty($data['email'])) {
            $patient->email = $data['email'];
        }

        $patient->save();

        return $patient;
    }

    private function generateBookingCode(Carbon $date): string
    {
        do {
            $bookingCode = 'LMR-BKG-'.$date->format('Ymd').'-'.strtoupper(Str::random(4));
        } while (Appointment::where('booking_code', $bookingCode)->exists());

        return $bookingCode;
    }
}
