<?php

namespace Tests\Feature;

use App\Contracts\ShippingProviderInterface;
use App\Jobs\CreateBiteshipShipmentJob;
use App\Models\Address;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Shipment;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class SameDayBookingWindowTest extends TestCase
{
    use RefreshDatabase;

    private const STORE = ['latitude' => -6.4701, 'longitude' => 106.8141];

    private const PIN = ['latitude' => -6.4903, 'longitude' => 106.8064];

    private User $customer;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('services.biteship.api_key', 'biteship_test.key');
        Config::set('services.biteship.instant_enabled', true);
        Config::set('services.biteship.origin_latitude', self::STORE['latitude']);
        Config::set('services.biteship.origin_longitude', self::STORE['longitude']);
        $this->customer = User::factory()->create(['role' => 'customer']);
        $this->admin = User::factory()->create(['role' => 'admin']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function at(string $time): void
    {
        Carbon::setTestNow(Carbon::parse("2026-10-09 {$time}", 'Asia/Jakarta'));
    }

    private function fakeRates(): void
    {
        Http::fake([
            'api.biteship.com/v1/rates/couriers*' => Http::response(['success' => true, 'pricing' => [
                ['courier_code' => 'jne', 'courier_name' => 'JNE', 'courier_service_code' => 'reg', 'courier_service_name' => 'Reguler', 'price' => 12000, 'duration' => '2 - 3 days'],
                ['courier_code' => 'gojek', 'courier_name' => 'Gojek', 'courier_service_code' => 'same_day', 'courier_service_name' => 'Same Day', 'price' => 20000, 'duration' => '6 - 8 hours'],
                ['courier_code' => 'gojek', 'courier_name' => 'Gojek', 'courier_service_code' => 'instant', 'courier_service_name' => 'Instant', 'price' => 28000, 'duration' => '1 - 2 hours'],
                ['courier_code' => 'grab', 'courier_name' => 'Grab', 'courier_service_code' => 'same_day', 'courier_service_name' => 'Same Day', 'price' => 22000, 'duration' => '6 - 8 hours'],
            ]], 200),
        ]);
    }

    private function address(): Address
    {
        return Address::factory()->create([
            'user_id' => $this->customer->id,
            'postal_code' => '16920',
            'latitude' => self::PIN['latitude'],
            'longitude' => self::PIN['longitude'],
        ]);
    }

    private function services(): array
    {
        $response = $this->actingAs($this->customer, 'sanctum')->postJson('/api/shipping/rates', [
            'destination' => $this->address()->id,
            'weight' => 300,
            'couriers' => ['jne', 'gojek', 'grab'],
            'items' => [['product_id' => Product::factory()->create(['weight' => 300])->id, 'quantity' => 1]],
        ])->assertOk();

        return array_map(fn (array $rate): string => $rate['courier'].' '.$rate['service'], $response->json('data'));
    }

    private function paidOrder(string $courier = 'GRAB', string $service = 'SAME_DAY', string $status = 'PAID'): Order
    {
        $order = Order::factory()->create(['user_id' => $this->customer->id, 'status' => $status, 'shipping_courier' => $courier, 'shipping_service' => $service]);
        Shipment::create(['order_id' => $order->id, 'courier' => $courier, 'service' => $service, 'status' => 'pending']);

        return $order;
    }

    public function test_same_day_is_offered_only_between_nine_and_two_in_the_afternoon(): void
    {
        $this->fakeRates();

        $this->at('20:38');
        $evening = $this->services();
        $this->assertEqualsCanonicalizing(['JNE REG', 'GOJEK INSTANT'], $evening);

        $this->at('10:00');
        $morning = $this->services();
        $this->assertEqualsCanonicalizing(['JNE REG', 'GOJEK SAME_DAY', 'GOJEK INSTANT', 'GRAB SAME_DAY'], $morning);

        $this->at('14:00');
        $this->assertNotContains('GRAB SAME_DAY', $this->services(), 'The window closes at 14.00.');
    }

    public function test_an_order_for_same_day_outside_the_window_is_refused_with_the_hours(): void
    {
        $this->fakeRates();
        $this->at('20:38');
        $product = Product::factory()->create(['price' => 100000, 'weight' => 300, 'stock' => 5, 'is_active' => true]);

        $this->actingAs($this->customer, 'sanctum')->postJson('/api/orders', [
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'address_id' => $this->address()->id,
            'courier' => 'grab',
            'service' => 'same_day',
        ])->assertUnprocessable()->assertJsonValidationErrors('shipping')
            ->assertJsonPath('errors.shipping.0', fn (string $message): bool => str_contains($message, '09.00 sampai 14.00 WIB'));

        $this->assertSame(0, Order::count());
        $this->assertEquals(5, $product->fresh()->stock);
    }

    public function test_staff_cannot_process_a_same_day_order_outside_the_window_and_it_stays_paid(): void
    {
        Queue::fake();
        $order = $this->paidOrder();

        $this->at('20:38');
        $this->actingAs($this->admin, 'sanctum')->postJson("/api/admin/orders/{$order->id}/process")
            ->assertUnprocessable()
            ->assertJsonPath('errors.shipment.0', fn (string $message): bool => str_contains($message, '09.00 sampai 14.00 WIB'));
        $this->assertSame('PAID', $order->fresh()->status);
        Queue::assertNothingPushed();

        $this->at('10:15');
        $this->actingAs($this->admin, 'sanctum')->postJson("/api/admin/orders/{$order->id}/process")->assertOk();
        $this->assertSame('PROCESSING', $order->fresh()->status);
        Queue::assertPushed(CreateBiteshipShipmentJob::class);
    }

    public function test_a_refused_booking_is_recorded_with_biteships_reason_and_can_be_booked_again(): void
    {
        $order = $this->paidOrder('GRAB', 'SAME_DAY', 'PROCESSING');
        OrderItem::factory()->create(['order_id' => $order->id, 'product_id' => Product::factory()->create(['weight' => 300])->id, 'quantity' => 1]);
        $order->update(['shipping_address' => ['name' => 'Yoshi', 'phone' => '0812', 'postal_code' => '16920', 'address' => 'Jl Test', ...self::PIN]]);
        Http::fake(['api.biteship.com/v1/orders' => Http::response(['success' => false, 'error' => 'Grab Same Day service time must between 09.00 and 14.00.', 'code' => 40002037], 400)]);

        $job = new CreateBiteshipShipmentJob($order->id);
        try {
            $job->handle(app(ShippingProviderInterface::class));
            $this->fail('The booking should have been refused.');
        } catch (\RuntimeException $exception) {
            $job->failed($exception);
        }

        $shipment = $order->shipment->fresh();
        $this->assertSame('booking_failed', $shipment->status);
        $this->assertStringContainsString('must between 09.00 and 14.00', $shipment->booking_error);
        $this->assertDatabaseHas('order_audit_logs', ['order_id' => $order->id, 'action' => 'COURIER_BOOKING_FAILED']);
        $this->assertContains('rebook_courier', $order->fresh('shipment')->allowed_actions);
        $this->actingAs($this->admin, 'sanctum')->getJson('/api/admin/operations/alerts')->assertJsonPath('data.shipments_without_courier', 1);

        Queue::fake();
        $this->at('20:38');
        $this->actingAs($this->admin, 'sanctum')->postJson("/api/admin/orders/{$order->id}/rebook-courier")->assertUnprocessable();

        $this->at('10:15');
        $this->actingAs($this->admin, 'sanctum')->postJson("/api/admin/orders/{$order->id}/rebook-courier")->assertOk();
        $this->assertDatabaseHas('shipments', ['order_id' => $order->id, 'status' => 'pending', 'booking_error' => null]);
        Queue::assertPushed(CreateBiteshipShipmentJob::class);
    }
}
