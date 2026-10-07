<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

class Appointment extends Model
{
    use HasFactory;

    /** Private disk that holds patient-uploaded clinical photos. */
    public const PHOTO_DISK = 'local';

    public const PHOTO_DIRECTORY = 'bookings';

    /** Minutes a signed clinical-photo URL stays valid. */
    public const PHOTO_URL_TTL_MINUTES = 30;

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

    /**
     * Expose the stored private photo path only as a short-lived signed URL.
     *
     * Values that are not private disk paths (absolute URLs or legacy data URIs) pass through unchanged.
     */
    protected function photoUrl(): Attribute
    {
        return Attribute::get(function (?string $value): ?string {
            if ($value === null || $value === '' || ! str_starts_with($value, self::PHOTO_DIRECTORY.'/')) {
                return $value;
            }

            return Storage::disk(self::PHOTO_DISK)->temporaryUrl($value, now()->addMinutes(self::PHOTO_URL_TTL_MINUTES));
        });
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
