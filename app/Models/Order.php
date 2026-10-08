<?php

namespace App\Models;

use App\Enums\OrderStatus;
use App\Enums\ShipmentStatus;
use App\Services\Shipping\InstantCourierPolicy;
use Database\Factories\OrderFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Order extends Model
{
    /** Aliases of {@see OrderStatus} kept for existing callers (UPPERCASE, persisted as-is). */
    public const STATUS_PENDING_PAYMENT = OrderStatus::PendingPayment->value;

    public const STATUS_PAID = OrderStatus::Paid->value;

    public const STATUS_PROCESSING = OrderStatus::Processing->value;

    public const STATUS_SHIPPED = OrderStatus::Shipped->value;

    public const STATUS_DELIVERED = OrderStatus::Delivered->value;

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
     * Customer-visible delivery steps, oldest first.
     *
     * @return HasMany<ShipmentEvent, $this>
     */
    public function shipmentEvents(): HasMany
    {
        return $this->hasMany(ShipmentEvent::class)->orderBy('occurred_at')->orderBy('id');
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
     * Whether the parcel travels with Gojek or Grab, whose progress comes only from Biteship.
     */
    public function usesInstantCourier(): bool
    {
        return InstantCourierPolicy::isInstant($this->shipment?->courier)
            || InstantCourierPolicy::isInstant($this->shipping_courier);
    }

    /**
     * Why staff may not mark this order shipped by hand, or null when they may.
     *
     * A driver booking is tracked by Biteship, so hand-entering a tracking number would show the
     * customer a parcel that no driver has picked up.
     */
    public function manualShipmentBlockReason(): ?string
    {
        if ($this->shipment?->status === ShipmentStatus::CourierNotFound->value) {
            return 'Biteship belum menemukan driver untuk pesanan ini. Pesan ulang kurir atau batalkan pesanan.';
        }

        if ($this->usesInstantCourier()) {
            return 'Pengiriman Gojek/Grab berjalan otomatis lewat Biteship dan tidak bisa ditandai terkirim manual. Tunggu kurir dijemput atau sinkronkan status pengiriman.';
        }

        return null;
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

        if ($this->canBeShipped() && $this->manualShipmentBlockReason() === null) {
            $actions[] = 'ship';
        }

        if ($this->canBeDelivered() && ! $this->usesInstantCourier()) {
            $actions[] = 'deliver';
        }

        if ($this->canBeCompleted()) {
            $actions[] = 'complete';
        }

        if (strtoupper($this->status) === self::STATUS_PROCESSING
            && $this->shipment?->status === ShipmentStatus::CourierNotFound->value) {
            $actions[] = 'rebook_courier';
        }

        if ($this->canBeCancelled()) {
            $actions[] = 'cancel';
        }

        return $actions;
    }
}
