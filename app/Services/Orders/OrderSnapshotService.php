<?php

namespace App\Services\Orders;

use App\Models\Address;
use App\Models\Order;
use App\Models\OrderItem;

class OrderSnapshotService
{
    /**
     * Build frozen address snapshot array from Address model.
     *
     * @return array<string, mixed>
     */
    public function createAddressSnapshot(Address $address): array
    {
        return [
            'recipient_name' => $address->recipient_name,
            'name' => $address->name,
            'phone' => $address->phone,
            'province' => $address->province,
            'city' => $address->city,
            'district' => $address->district,
            'postal_code' => $address->postal_code,
            'address' => $address->address,
            'address_line' => $address->address_line,
            'address_detail' => $address->address_detail,
        ];
    }

    /**
     * Create immutable OrderItem records for the created order.
     *
     * @param  array<int, array{product_id: int, product_name: string, unit_price: float, quantity: int, subtotal: float}>  $itemsData
     */
    public function createOrderItemsSnapshot(Order $order, array $itemsData): void
    {
        foreach ($itemsData as $itemData) {
            OrderItem::create([
                'order_id' => $order->id,
                'product_id' => $itemData['product_id'],
                'product_name' => $itemData['product_name'],
                'unit_price' => $itemData['unit_price'],
                'quantity' => $itemData['quantity'],
                'subtotal' => $itemData['subtotal'],
            ]);
        }
    }
}
