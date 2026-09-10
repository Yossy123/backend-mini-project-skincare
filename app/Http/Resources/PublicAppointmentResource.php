<?php

namespace App\Http\Resources;

use App\Models\Appointment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Safe, minimal appointment representation for public booking confirmation.
 *
 * @mixin Appointment
 */
class PublicAppointmentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'booking_code' => $this->booking_code,
            'appointment_date' => $this->appointment_date?->format('Y-m-d'),
            'start_time' => $this->start_time,
            'end_time' => $this->end_time,
            'consultation_mode' => $this->consultation_mode,
            'status' => $this->status,
            'patient' => $this->patient ? [
                'name' => $this->patient->name,
            ] : null,
            'doctor' => $this->doctor ? [
                'id' => $this->doctor->id,
                'name' => $this->doctor->name,
                'title' => $this->doctor->title,
                'specialization' => $this->doctor->specialization,
            ] : null,
            'service' => $this->service ? [
                'id' => $this->service->id,
                'code' => $this->service->code,
                'name' => $this->service->name,
                'duration_minutes' => $this->service->duration_minutes,
            ] : null,
        ];
    }
}
