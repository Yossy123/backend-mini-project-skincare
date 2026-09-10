<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Appointment extends Model
{
    use HasFactory;

    protected $fillable = [
        'booking_code',
        'patient_id',
        'doctor_id',
        'service_id',
        'appointment_date',
        'start_time',
        'end_time',
        'consultation_mode',
        'complaint',
        'patient_notes',
        'photo_url',
        'doctor_notes',
        'diagnosis',
        'treatment_plan',
        'prescription',
        'status',
        'cancellation_reason',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'appointment_date' => 'date:Y-m-d',
        ];
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function doctor(): BelongsTo
    {
        return $this->belongsTo(Doctor::class);
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function statusHistories(): HasMany
    {
        return $this->hasMany(AppointmentStatusHistory::class)->orderByDesc('created_at');
    }

    public function logStatusChange(string $newStatus, ?int $changedBy = null, ?string $notes = null): void
    {
        $this->statusHistories()->create([
            'from_status' => $this->status,
            'to_status' => $newStatus,
            'changed_by' => $changedBy,
            'notes' => $notes,
        ]);
        $this->status = $newStatus;
        $this->save();
    }
}
