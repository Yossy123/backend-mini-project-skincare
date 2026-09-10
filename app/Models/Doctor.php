<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Doctor extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'name',
        'title',
        'specialization',
        'license_number',
        'phone',
        'experience',
        'rating',
        'review_count',
        'avatar_color',
        'bio',
        'skills',
        'schedule_days',
        'available_days',
        'work_start_time',
        'work_end_time',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'rating' => 'decimal:2',
            'review_count' => 'integer',
            'skills' => 'array',
            'available_days' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function appointments(): HasMany
    {
        return $this->hasMany(Appointment::class);
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }
}
