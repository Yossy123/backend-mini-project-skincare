<?php

namespace App\Models;

use Database\Factories\AddressFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Address extends Model
{
    /** @use HasFactory<AddressFactory> */
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'label',
        'recipient_name',
        'name',
        'phone',
        'province',
        'city',
        'district',
        'postal_code',
        'biteship_area_id',
        'address',
        'address_line',
        'address_detail',
        'is_default',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'biteship_area_id' => 'string',
            'is_default' => 'boolean',
        ];
    }

    /**
     * Synchronize name and recipient_name attributes.
     */
    public function setRecipientNameAttribute(?string $value): void
    {
        $this->attributes['recipient_name'] = $value;
        if (empty($this->attributes['name'])) {
            $this->attributes['name'] = $value;
        }
    }

    public function setNameAttribute(?string $value): void
    {
        $this->attributes['name'] = $value;
        if (empty($this->attributes['recipient_name'])) {
            $this->attributes['recipient_name'] = $value;
        }
    }

    /**
     * Synchronize address and address_line attributes.
     */
    public function setAddressLineAttribute(?string $value): void
    {
        $this->attributes['address_line'] = $value;
        if (empty($this->attributes['address'])) {
            $this->attributes['address'] = $value;
        }
    }

    public function setAddressAttribute(?string $value): void
    {
        $this->attributes['address'] = $value;
        if (empty($this->attributes['address_line'])) {
            $this->attributes['address_line'] = $value;
        }
    }

    /**
     * Get effective recipient name.
     */
    public function getRecipientNameAttribute(): string
    {
        return $this->attributes['recipient_name'] ?? $this->attributes['name'] ?? '';
    }

    /**
     * Get effective street address line.
     */
    public function getAddressLineAttribute(): string
    {
        return $this->attributes['address_line'] ?? $this->attributes['address'] ?? '';
    }

    /**
     * Get the user that owns the address.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
