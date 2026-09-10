<?php

namespace App\Models;

use Database\Factories\PaymentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Payment extends Model
{
    /** @use HasFactory<PaymentFactory> */
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'order_id',
        'provider',
        'transaction_id',
        'snap_token',
        'redirect_url',
        'payment_type',
        'status',
        'amount',
        'paid_at',
        'expires_at',
        'raw_response',
        'refund_id',
        'refund_amount',
        'refund_reason',
        'refunded_at',
        'refund_raw_response',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'paid_at' => 'datetime',
            'expires_at' => 'datetime',
            'raw_response' => 'array',
            'refund_amount' => 'decimal:2',
            'refunded_at' => 'datetime',
            'refund_raw_response' => 'array',
        ];
    }

    /**
     * Get the order associated with the payment.
     *
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * Determine if payment has been refunded.
     */
    public function isRefunded(): bool
    {
        return $this->status === 'refunded' || ! empty($this->refunded_at);
    }

    /**
     * Determine if payment is eligible for a refund.
     */
    public function canBeRefunded(): bool
    {
        $eligibleStatuses = ['settlement', 'capture', 'success', 'paid'];

        return in_array(strtolower($this->status), $eligibleStatuses, true) && ! $this->isRefunded();
    }
}
