<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Shipment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class OrderCancellationShipmentTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected string $adminToken;

    protected User $customer;

    protected Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();

        $this->admin = User::factory()->create(['role' => 'admin']);
        $this->adminToken = $this->admin->createToken('admin_token')->plainTextToken;
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

    protected function createCancellableOrderWithShipment(?string $biteshipOrderId = null): Order
    {
        $subtotal = $this->product->price;
        $shippingCost = 10000;

        $order = Order::create([
            'user_id' => $this->customer->id,
            'status' => 'PAID',
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
        $this->product->decrement('stock', 1);

        Shipment::create([
            'order_id' => $order->id,
            'biteship_order_id' => $biteshipOrderId,
            'courier' => 'JNE',
            'service' => 'REG',
            'tracking_number' => $biteshipOrderId ? 'WYB-123' : null,
            'status' => $biteshipOrderId ? 'processing' : 'pending',
        ]);

        return $order->fresh();
    }

    public function test_cancel_order_with_biteship_booking_cancels_courier_shipment(): void
    {
        Http::fake([
            'api.biteship.com/v1/orders/bit_booking_123/cancel*' => Http::response([
                'success' => true,
                'messages' => 'Order successfully cancelled',
                'id' => 'bit_booking_123',
                'status' => 'cancelled',
            ], 200),
        ]);

        $order = $this->createCancellableOrderWithShipment('bit_booking_123');

        $response = $this->withHeader('Authorization', "Bearer {$this->adminToken}")
            ->postJson("/api/admin/orders/{$order->id}/cancel", [
                'reason' => 'customer_request',
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.status', 'CANCELLED');

        Http::assertSent(function ($request) {
            return str_starts_with($request->url(), 'https://api.biteship.com/v1/orders/bit_booking_123/cancel')
                && $request['cancellation_reason'] === 'others';
        });

        $this->assertEquals('cancelled', $order->shipment->fresh()->status);
    }

    public function test_cancel_order_still_succeeds_when_courier_cancellation_fails(): void
    {
        Http::fake([
            'api.biteship.com/v1/orders/bit_booking_123/cancel*' => Http::response([
                'error' => 'shipment already picked up by courier',
            ], 422),
        ]);

        $order = $this->createCancellableOrderWithShipment('bit_booking_123');

        $response = $this->withHeader('Authorization', "Bearer {$this->adminToken}")
            ->postJson("/api/admin/orders/{$order->id}/cancel", [
                'reason' => 'shipping_issue',
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.status', 'CANCELLED');

        // Local cancellation must survive; shipment telemetry stays under webhook control.
        $this->assertEquals(10, $this->product->fresh()->stock);
        $this->assertEquals('processing', $order->shipment->fresh()->status);
    }

    public function test_cancel_order_without_biteship_booking_marks_shipment_cancelled_locally(): void
    {
        Http::fake();

        $order = $this->createCancellableOrderWithShipment(null);

        $response = $this->withHeader('Authorization', "Bearer {$this->adminToken}")
            ->postJson("/api/admin/orders/{$order->id}/cancel", [
                'reason' => 'duplicate_order',
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.status', 'CANCELLED');

        Http::assertNothingSent();
        $this->assertEquals('cancelled', $order->shipment->fresh()->status);
    }
}
