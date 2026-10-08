<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Payment;
use App\Models\Shipment;
use App\Models\ShipmentEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class OrderCompletionTest extends TestCase
{
    use RefreshDatabase;

    private function deliveredOrder(User $customer, int $deliveredDaysAgo = 0): Order
    {
        $order = Order::factory()->create(['user_id' => $customer->id, 'status' => 'DELIVERED', 'shipping_courier' => 'JNE']);
        Shipment::create(['order_id' => $order->id, 'courier' => 'JNE', 'service' => 'REG', 'status' => 'delivered', 'delivered_at' => now()->subDays($deliveredDaysAgo)]);

        return $order;
    }

    public function test_a_customer_confirms_their_delivered_order_which_completes_it_and_tells_them(): void
    {
        $customer = User::factory()->create();
        $order = $this->deliveredOrder($customer);

        $this->actingAs($customer, 'sanctum')->postJson("/api/orders/{$order->id}/confirm-received")
            ->assertOk()
            ->assertJsonPath('data.status', 'COMPLETED')
            ->assertJsonPath('data.tracking_events.0.title', 'Pesanan selesai');

        $this->assertDatabaseHas('order_audit_logs', ['order_id' => $order->id, 'action' => 'ORDER_COMPLETED', 'admin_id' => null]);
        $this->assertSame(1, $customer->notifications()->count());
    }

    public function test_confirming_receipt_is_limited_to_the_owner_and_to_delivered_orders(): void
    {
        $customer = User::factory()->create();
        $stranger = User::factory()->create();
        $delivered = $this->deliveredOrder($customer);
        $shipped = Order::factory()->create(['user_id' => $customer->id, 'status' => 'SHIPPED']);

        $this->actingAs($stranger, 'sanctum')->postJson("/api/orders/{$delivered->id}/confirm-received")->assertNotFound();
        $this->actingAs($customer, 'sanctum')->postJson("/api/orders/{$shipped->id}/confirm-received")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('status');

        $this->assertSame('DELIVERED', $delivered->fresh()->status);
        $this->assertSame('SHIPPED', $shipped->fresh()->status);
    }

    public function test_unconfirmed_orders_complete_automatically_after_the_configured_days_unless_flagged_for_review(): void
    {
        Config::set('orders.auto_complete_days', 3);
        $customer = User::factory()->create();
        $old = $this->deliveredOrder($customer, 4);
        $recent = $this->deliveredOrder($customer, 1);
        $flagged = $this->deliveredOrder($customer, 10);
        Payment::factory()->create(['order_id' => $flagged->id, 'requires_review' => true]);

        $this->artisan('orders:complete-delivered')->assertSuccessful();

        $this->assertSame('COMPLETED', $old->fresh()->status);
        $this->assertSame('DELIVERED', $recent->fresh()->status);
        $this->assertSame('DELIVERED', $flagged->fresh()->status);
        $this->assertDatabaseHas('order_audit_logs', ['order_id' => $old->id, 'action' => 'ORDER_COMPLETED', 'admin_id' => null]);
        $this->assertSame(1, ShipmentEvent::where('status', 'completed')->count());
    }
}
