<?php

namespace Tests\Feature;

use App\Contracts\ShippingProviderInterface;
use App\Jobs\CreateBiteshipShipmentJob;
use App\Models\Category;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Shipment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class CreateBiteshipShipmentJobTest extends TestCase
{
    use RefreshDatabase;

    protected User $customer;

    protected Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->customer = User::factory()->create(['role' => 'customer']);
        $category = Category::create(['name' => 'Skincare', 'slug' => 'skincare', 'is_active' => true]);
        $this->product = Product::create([
            'category_id' => $category->id,
            'name' => 'Radiance Serum 30ml',
            'slug' => 'radiance-serum-30ml',
            'price' => 150000,
            'weight' => 100,
            'stock' => 10,
            'is_active' => true,
        ]);
    }

    protected function createOrderWithShipment(string $status = 'PAID'): Order
    {
        $subtotal = $this->product->price;
        $shippingCost = 10000;

        $order = Order::create([
            'user_id' => $this->customer->id,
            'status' => $status,
            'subtotal' => $subtotal,
            'shipping_cost' => $shippingCost,
            'total' => $subtotal + $shippingCost,
            'shipping_courier' => 'JNE',
            'shipping_service' => 'REG',
            'shipping_address' => [
                'name' => 'Elena Rostova',
                'phone' => '+62812345678',
                'province' => 'DKI Jakarta',
                'city' => 'Jakarta Selatan',
                'district' => 'Kebayoran Baru',
                'postal_code' => '12110',
                'address' => 'Jl. Senopati No. 45',
            ],
        ]);

        OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $this->product->id,
            'product_name' => $this->product->name,
            'unit_price' => $this->product->price,
            'quantity' => 1,
            'subtotal' => $subtotal,
        ]);

        Shipment::create([
            'order_id' => $order->id,
            'courier' => 'JNE',
            'service' => 'REG',
            'tracking_number' => null,
            'status' => 'pending',
        ]);

        return $order->fresh();
    }

    public function test_job_creates_biteship_shipment_for_paid_order(): void
    {
        Http::fake([
            'api.biteship.com/v1/orders' => Http::response([
                'success' => true,
                'id' => 'bit_order_123',
                'courier' => [
                    'tracking_id' => 'trk_abc',
                    'waybill_id' => 'WYB-123',
                    'company' => 'jne',
                    'type' => 'reg',
                ],
                'status' => 'confirmed',
                'price' => 10000,
            ], 200),
        ]);

        $order = $this->createOrderWithShipment('PAID');

        (new CreateBiteshipShipmentJob($order->id))->handle(
            app(ShippingProviderInterface::class)
        );

        $shipment = $order->shipment->fresh();

        $this->assertEquals('bit_order_123', $shipment->biteship_order_id);
        $this->assertEquals('trk_abc', $shipment->biteship_tracking_id);
        $this->assertEquals('WYB-123', $shipment->biteship_waybill_id);
        $this->assertEquals('WYB-123', $shipment->tracking_number);
        $this->assertEquals('processing', $shipment->status);

        Http::assertSent(function ($request) {
            return str_starts_with($request->url(), 'https://api.biteship.com/v1/orders')
                && $request['destination_postal_code'] === 12110
                && $request['courier_company'] === 'jne';
        });
    }

    public function test_job_is_idempotent_when_biteship_order_id_already_exists(): void
    {
        Http::fake();

        $order = $this->createOrderWithShipment('PROCESSING');
        $order->shipment->update([
            'biteship_order_id' => 'bit_existing',
            'status' => 'processing',
        ]);

        (new CreateBiteshipShipmentJob($order->id))->handle(
            app(ShippingProviderInterface::class)
        );

        Http::assertNothingSent();
        $this->assertEquals('bit_existing', $order->shipment->fresh()->biteship_order_id);
    }

    public function test_job_skips_orders_outside_paid_or_processing_status(): void
    {
        Http::fake();

        $order = $this->createOrderWithShipment('PENDING_PAYMENT');

        (new CreateBiteshipShipmentJob($order->id))->handle(
            app(ShippingProviderInterface::class)
        );

        Http::assertNothingSent();
        $this->assertNull($order->shipment->fresh()->biteship_order_id);
        $this->assertEquals('pending', $order->shipment->fresh()->status);
    }

    public function test_job_throws_and_leaves_shipment_untouched_on_provider_failure(): void
    {
        Http::fake([
            'api.biteship.com/v1/orders' => Http::response(['error' => 'insufficient balance'], 500),
        ]);

        $order = $this->createOrderWithShipment('PAID');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Biteship shipment creation failed.');

        try {
            (new CreateBiteshipShipmentJob($order->id))->handle(
                app(ShippingProviderInterface::class)
            );
        } finally {
            $shipment = $order->shipment->fresh();
            $this->assertNull($shipment->biteship_order_id);
            $this->assertEquals('pending', $shipment->status);
        }
    }

    public function test_job_skips_when_shipment_record_missing(): void
    {
        Http::fake();

        $order = $this->createOrderWithShipment('PAID');
        $order->shipment->delete();

        (new CreateBiteshipShipmentJob($order->id))->handle(
            app(ShippingProviderInterface::class)
        );

        Http::assertNothingSent();
    }
}
