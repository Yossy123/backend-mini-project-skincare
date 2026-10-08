<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One customer-visible step in an order's delivery, shown as the tracking timeline.
 */
class ShipmentEvent extends Model
{
    /** @var list<string> */
    protected $fillable = ['order_id', 'status', 'title', 'message', 'occurred_at'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['occurred_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
