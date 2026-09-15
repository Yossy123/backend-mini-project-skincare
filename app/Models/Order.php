<?php

namespace App\Models;

use Database\Factories\OrderFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Order extends Model
{
    /** Canonical order lifecycle statuses (UPPERCASE, persisted as-is). */
    public const STATUS_PENDING_PAYMENT = 'PENDING_PAYMENT';
    public const STATUS_PAID = 'PAID';
    public const STATUS_PROCESSING = 'PROCESSING';
    public const STATUS_SHIPPED = 'SHIPPED';
    public const STATUS_DELIVERED = 'DELIVERED';

    /** @use HasFactory<OrderFactory> */
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'idempotency_key',
        'status',
        'subtotal',
        'shipping_cost',
        'total',
        'shipping_courier',
        'shipping_service',
        'shipping_etd',
        'shipping_address',
        'cancellation_reason',
        'cancellation_note',
        'cancelled_by',
        'cancelled_at',
        'stock_restored_at',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'subtotal' => 'decimal:2',
            'shipping_cost' => 'decimal:2',
            'total' => 'decimal:2',
            'shipping_address' => 'array',
            'cancelled_at' => 'datetime',
            'stock_restored_at' => 'datetime',
        ];
    }

    /**
     * Get the user that placed the order.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the admin user who cancelled the order.
     *
     * @return BelongsTo<User, $this>
     */
    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    /**
     * Get all items in this order.
     *
     * @return HasMany<OrderItem, $this>
     */
    public function orderItems(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    /**
     * Get the payment associated with the order.
     *
     * @return HasOne<Payment, $this>
     */
    public function payment(): HasOne
    {
        return $this->hasOne(Payment::class);
    }

    /**
     * Get the shipment associated with the order.
     *
     * @return HasOne<Shipment, $this>
     */
    public function shipment(): HasOne
    {
        return $this->hasOne(Shipment::class);
    }

    /**
     * Get all audit log history for this order.
     *
     * @return HasMany<OrderAuditLog, $this>
     */
    public function auditLogs(): HasMany
    {
        return $this->hasMany(OrderAuditLog::class)->orderByDesc('created_at');
    }

    /**
     * Determine if order can transition to PROCESSING.
     */
    public function canBeProcessed(): bool
    {
        return strtoupper($this->status) === self::STATUS_PAID;
    }

    /**
     * Determine if order can transition to SHIPPED.
     */
    public function canBeShipped(): bool
    {
        return strtoupper($this->status) === self::STATUS_PROCESSING;
    }

    /**
     * Determine if order can transition to DELIVERED.
     */
    public function canBeDelivered(): bool
    {
        return strtoupper($this->status) === self::STATUS_SHIPPED;
    }

    /**
     * Determine if order can transition to COMPLETED.
     */
    public function canBeCompleted(): bool
    {
        return strtoupper($this->status) === self::STATUS_DELIVERED;
    }

    /**
     * Determine if order can be cancelled.
     */
    public function canBeCancelled(): bool
    {
        return in_array(strtoupper($this->status), [self::STATUS_PENDING_PAYMENT, self::STATUS_PAID, self::STATUS_PROCESSING], true);
    }

    /**
     * Get array of allowed state transitions.
     *
     * @return list<string>
     */
    public function getAllowedActionsAttribute(): array
    {
        $actions = [];

        if ($this->canBeProcessed()) {
            $actions[] = 'process';
        }

        if ($this->canBeShipped()) {
            $actions[] = 'ship';
        }

        if ($this->canBeDelivered()) {
            $actions[] = 'deliver';
        }

        if ($this->canBeCompleted()) {
            $actions[] = 'complete';
        }

        if ($this->canBeCancelled()) {
            $actions[] = 'cancel';
        }

        return $actions;
    }
}
