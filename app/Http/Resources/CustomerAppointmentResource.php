<?php

namespace App\Http\Resources;

use App\Models\Appointment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Customer-safe appointment representation. Clinical staff notes remain
 * restricted to the doctor/admin endpoints.
 *
 * @mixin Appointment
 */
class CustomerAppointmentResource extends JsonResource
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
            'complaint' => $this->complaint,
            'patient_notes' => $this->patient_notes,
            'status' => $this->status,
            'cancellation_reason' => $this->cancellation_reason,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'patient' => $this->patient ? [
                'id' => $this->patient->id,
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
            'status_histories' => $this->whenLoaded('statusHistories', fn () => $this->statusHistories->map(fn ($history) => [
                'id' => $history->id,
                'from_status' => $history->from_status,
                'to_status' => $history->to_status,
                'created_at' => $history->created_at?->toIso8601String(),
            ])->values()),
        ];
    }
}
