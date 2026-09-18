<?php

namespace Database\Seeders;

use App\Models\Appointment;
use App\Models\Doctor;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Patient;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Shipment;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DashboardDemoSeeder extends Seeder
{
    public function run(): void
    {
        $customer = User::firstOrCreate(
            ['email' => 'customer@lumiere.com'],
            [
                'name' => 'Aurelia Vance',
                'role' => 'customer',
                'phone' => '+6281234567890',
                'password' => Hash::make('password'),
                'is_active' => true,
            ]
        );

        $secondCustomer = User::firstOrCreate(
            ['email' => 'demo.customer@nobodyderm.com'],
            [
                'name' => 'Nadia Pratama',
                'role' => 'customer',
                'phone' => '+6281234567891',
                'password' => Hash::make('password'),
                'is_active' => true,
            ]
        );

        $products = Product::whereIn('slug', [
            'lumiere-radiance-vitamin-c-serum-30ml',
            'hydra-luxe-ceramide-barrier-cream-50ml',
            'gentle-botanical-cleansing-balm-100g',
        ])->get()->keyBy('slug');

        $serum = $products->get('lumiere-radiance-vitamin-c-serum-30ml');
        $cream = $products->get('hydra-luxe-ceramide-barrier-cream-50ml');
        $cleanser = $products->get('gentle-botanical-cleansing-balm-100g');

        if (! $serum || ! $cream || ! $cleanser) {
            return;
        }

        $now = Carbon::now();
        $orderExamples = [
            [
                'customer' => $customer,
                'key' => 'demo-dashboard-paid',
                'status' => 'PAID',
                'created_at' => $now->copy()->subDay(),
                'items' => [[$serum, 2], [$cream, 1]],
                'payment_status' => 'settlement',
                'shipment_status' => 'pending',
            ],
            [
                'customer' => $secondCustomer,
                'key' => 'demo-dashboard-processing',
                'status' => 'PROCESSING',
                'created_at' => $now->copy()->subDays(4),
                'items' => [[$cream, 1]],
                'payment_status' => 'settlement',
                'shipment_status' => 'pending',
            ],
            [
                'customer' => $customer,
                'key' => 'demo-dashboard-completed',
                'status' => 'COMPLETED',
                'created_at' => $now->copy()->subDays(18),
                'items' => [[$serum, 1], [$cleanser, 1]],
                'payment_status' => 'settlement',
                'shipment_status' => 'delivered',
            ],
            [
                'customer' => $secondCustomer,
                'key' => 'demo-dashboard-unpaid',
                'status' => 'PENDING_PAYMENT',
                'created_at' => $now->copy()->subHours(36),
                'items' => [[$cleanser, 1]],
                'payment_status' => 'pending',
                'shipment_status' => null,
            ],
        ];

        foreach ($orderExamples as $example) {
            $subtotal = collect($example['items'])->sum(
                fn (array $item) => (float) $item[0]->price * $item[1]
            );
            $shippingCost = 22000;
            $order = Order::firstOrCreate(
                [
                    'user_id' => $example['customer']->id,
                    'idempotency_key' => $example['key'],
                ],
                [
                    'status' => $example['status'],
                    'subtotal' => $subtotal,
                    'shipping_cost' => $shippingCost,
                    'total' => $subtotal + $shippingCost,
                    'shipping_courier' => 'JNE',
                    'shipping_service' => 'REG',
                    'shipping_etd' => '1-2 hari',
                    'shipping_address' => [
                        'recipient_name' => $example['customer']->name,
                        'phone' => $example['customer']->phone,
                        'province' => 'DKI Jakarta',
                        'city' => 'Jakarta Selatan',
                        'district' => 'Kebayoran Baru',
                        'postal_code' => '12190',
                        'address' => 'Jl. Contoh No. 10',
                    ],
                ]
            );

            if ($order->wasRecentlyCreated) {
                $order->timestamps = false;
                $order->forceFill([
                    'created_at' => $example['created_at'],
                    'updated_at' => $example['created_at'],
                ])->save();
                $order->timestamps = true;
            }

            foreach ($example['items'] as [$product, $quantity]) {
                OrderItem::firstOrCreate(
                    ['order_id' => $order->id, 'product_id' => $product->id],
                    [
                        'product_name' => $product->name,
                        'unit_price' => $product->price,
                        'quantity' => $quantity,
                        'subtotal' => $product->price * $quantity,
                    ]
                );
            }

            Payment::firstOrCreate(
                ['order_id' => $order->id],
                [
                    'provider' => 'midtrans',
                    'transaction_id' => 'TRX-'.strtoupper($example['key']),
                    'status' => $example['payment_status'],
                    'amount' => $order->total,
                    'paid_at' => $example['payment_status'] === 'pending' ? null : $example['created_at'],
                    'raw_response' => [
                        'transaction_status' => $example['payment_status'],
                        'payment_type' => 'qris',
                        'demo_data' => true,
                    ],
                ]
            );

            if ($example['shipment_status']) {
                Shipment::firstOrCreate(
                    ['order_id' => $order->id],
                    [
                        'courier' => 'JNE',
                        'service' => 'REG',
                        'tracking_number' => 'DEMO-'.$order->id,
                        'status' => $example['shipment_status'],
                        'shipped_at' => $example['shipment_status'] === 'delivered' ? $example['created_at']->copy()->addDay() : null,
                        'delivered_at' => $example['shipment_status'] === 'delivered' ? $example['created_at']->copy()->addDays(2) : null,
                    ]
                );
            }
        }

        $this->seedDoctorAppointments($now);
    }

    private function seedDoctorAppointments(Carbon $now): void
    {
        $doctor = Doctor::whereHas('user', fn ($query) => $query->where('email', 'doctor.yoshi@nobodyderm.com'))->first();
        $patients = Patient::whereIn('phone', ['+6281234567890', '081298765432'])->get()->keyBy('phone');
        $facial = \App\Models\Service::where('code', 'SRV001')->first();
        $acne = \App\Models\Service::where('code', 'SRV002')->first();

        if (! $doctor || ! $facial || ! $acne || ! $patients->has('+6281234567890') || ! $patients->has('081298765432')) {
            return;
        }

        $today = $now->copy()->startOfDay();
        $examples = [
            [
                'suffix' => 'DEMO-CHECKED',
                'patient' => $patients->get('081298765432'),
                'service' => $acne,
                'date' => $today,
                'start_time' => '11:00:00',
                'end_time' => '12:00:00',
                'status' => 'checked_in',
                'complaint' => 'Contoh pasien sudah hadir dan siap menunggu konsultasi.',
            ],
            [
                'suffix' => 'DEMO-PROGRESS',
                'patient' => $patients->get('+6281234567890'),
                'service' => $facial,
                'date' => $today,
                'start_time' => '13:00:00',
                'end_time' => '14:00:00',
                'status' => 'in_progress',
                'complaint' => 'Contoh kunjungan yang sedang berlangsung.',
            ],
            [
                'suffix' => 'DEMO-COMPLETED',
                'patient' => $patients->get('+6281234567890'),
                'service' => $acne,
                'date' => $today,
                'start_time' => '09:00:00',
                'end_time' => '10:00:00',
                'status' => 'completed',
                'complaint' => 'Contoh kunjungan selesai untuk pratinjau ringkasan.',
            ],
            [
                'suffix' => 'DEMO-UPCOMING',
                'patient' => $patients->get('081298765432'),
                'service' => $facial,
                'date' => $today->copy()->addDay(),
                'start_time' => '15:00:00',
                'end_time' => '16:00:00',
                'status' => 'confirmed',
                'complaint' => 'Contoh reservasi untuk jadwal mendatang.',
            ],
        ];

        foreach ($examples as $example) {
            $status = $example['status'];
            $appointment = Appointment::firstOrCreate(
                ['booking_code' => 'DEMO-'.$example['suffix'].'-'.$example['date']->format('Ymd')],
                [
                    'patient_id' => $example['patient']->id,
                    'doctor_id' => $doctor->id,
                    'service_id' => $example['service']->id,
                    'appointment_date' => $example['date']->toDateString(),
                    'start_time' => $example['start_time'],
                    'end_time' => $example['end_time'],
                    'consultation_mode' => 'offline',
                    'complaint' => $example['complaint'],
                    'status' => $status,
                ]
            );

            if ($appointment->wasRecentlyCreated) {
                $appointment->statusHistories()->firstOrCreate(
                    ['to_status' => $status],
                    ['from_status' => null, 'notes' => 'Contoh data dashboard.']
                );
            }
        }
    }
}