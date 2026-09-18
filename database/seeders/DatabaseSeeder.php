<?php

namespace Database\Seeders;

use App\Models\Address;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Shipment;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        // 1. Seed Categories, Products, and Clinical Booking System
        $this->call([
            CategorySeeder::class,
            ProductSeeder::class,
        ]);

        // 2. Create Admin and standard Demo Customer
        $admin = User::firstOrCreate(
            ['email' => 'admin@nobodyderm.com'],
            [
                'name' => 'Lumiere Admin',
                'role' => 'admin',
                'phone' => '+628110000000',
                'password' => bcrypt('password'),
            ]
        );

        $customer = User::firstOrCreate(
            ['email' => 'customer@lumiere.com'],
            [
                'name' => 'Aurelia Vance',
                'role' => 'customer',
                'phone' => '+6281234567890',
                'password' => bcrypt('password'),
            ]
        );

        $this->call(BookingSystemSeeder::class);

        // 3. Create Addresses for Customer
        $address = Address::firstOrCreate(
            [
                'user_id' => $customer->id,
                'name' => 'Aurelia Vance (Home)',
            ],
            [
                'phone' => '+6281234567890',
                'province' => 'DKI Jakarta',
                'city' => 'Jakarta Selatan',
                'district' => 'Senopati',
                'postal_code' => '12190',
                'address' => 'Jl. Senopati No. 42, Kebayoran Baru',
                'is_default' => true,
            ]
        );

        $this->call(DashboardDemoSeeder::class);

        // 4. Create sample Order with OrderItems, Payment, and Shipment
        $serum = Product::where('slug', 'lumiere-radiance-vitamin-c-serum-30ml')->first();
        $cream = Product::where('slug', 'hydra-luxe-ceramide-barrier-cream-50ml')->first();

        if ($serum && $cream) {
            $item1Qty = 2;
            $item1Subtotal = $serum->price * $item1Qty;

            $item2Qty = 1;
            $item2Subtotal = $cream->price * $item2Qty;

            $orderSubtotal = $item1Subtotal + $item2Subtotal;
            $shippingCost = 22000.00;
            $orderTotal = $orderSubtotal + $shippingCost;

            $order = Order::firstOrCreate(
                [
                    'user_id' => $customer->id,
                    'total' => $orderTotal,
                ],
                [
                    'status' => 'PAID',
                    'subtotal' => $orderSubtotal,
                    'shipping_cost' => $shippingCost,
                    'shipping_courier' => 'JNE',
                    'shipping_service' => 'YES',
                    'shipping_etd' => '1 day',
                    'shipping_address' => [
                        'recipient_name' => $address->name,
                        'phone' => $address->phone,
                        'province' => $address->province,
                        'city' => $address->city,
                        'district' => $address->district,
                        'postal_code' => $address->postal_code,
                        'address' => $address->address,
                    ],
                ]
            );

            OrderItem::firstOrCreate(
                ['order_id' => $order->id, 'product_id' => $serum->id],
                [
                    'product_name' => $serum->name,
                    'unit_price' => $serum->price,
                    'quantity' => $item1Qty,
                    'subtotal' => $item1Subtotal,
                ]
            );

            OrderItem::firstOrCreate(
                ['order_id' => $order->id, 'product_id' => $cream->id],
                [
                    'product_name' => $cream->name,
                    'unit_price' => $cream->price,
                    'quantity' => $item2Qty,
                    'subtotal' => $item2Subtotal,
                ]
            );

            Payment::firstOrCreate(
                ['order_id' => $order->id],
                [
                    'provider' => 'midtrans',
                    'transaction_id' => 'TRX-LUMIERE-20260903-001',
                    'status' => 'paid',
                    'amount' => $orderTotal,
                    'paid_at' => now(),
                    'raw_response' => [
                        'status_code' => '200',
                        'transaction_status' => 'settlement',
                        'payment_type' => 'qris',
                    ],
                ]
            );

            Shipment::firstOrCreate(
                ['order_id' => $order->id],
                [
                    'courier' => 'JNE',
                    'service' => 'YES',
                    'tracking_number' => 'DEMO-BASE-'.$order->id,
                    'status' => 'pending',
                ]
            );
        }
    }
}
