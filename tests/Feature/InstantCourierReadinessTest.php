<?php

namespace Tests\Feature;

use App\Jobs\CreateBiteshipShipmentJob;
use App\Models\Address;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Shipment;
use App\Models\User;
use App\Services\Shipping\BiteshipShipmentService;
use App\Services\Shipping\InstantCourierPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class InstantCourierReadinessTest extends TestCase
{
    use RefreshDatabase;

    /** Store pickup point (Bogor) and a customer pin (Bojonggede). */
    private const STORE = ['latitude' => -6.5944, 'longitude' => 106.7892];

    private const CUSTOMER_PIN = ['latitude' => -6.4903, 'longitude' => 106.8064];

    private User $customer;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('services.biteship.api_key', 'biteship_test.key');
        Config::set('services.biteship.webhook_signature_key', 'X-Biteship-Signature');
        Config::set('services.biteship.webhook_secret', 'test-webhook-secret');
        $this->customer = User::factory()->create(['role' => 'customer']);
    }

    private function instant(bool $enabled, ?array $origin = self::STORE): void
    {
        Config::set('services.biteship.instant_enabled', $enabled);
        Config::set('services.biteship.origin_latitude', $origin['latitude'] ?? null);
        Config::set('services.biteship.origin_longitude', $origin['longitude'] ?? null);
    }

    private function address(?array $pin, array $overrides = []): Address
    {
        return Address::factory()->create([
            'user_id' => $this->customer->id,
            'postal_code' => '16920',
            'latitude' => $pin['latitude'] ?? null,
            'longitude' => $pin['longitude'] ?? null,
            ...$overrides,
        ]);
    }

    private function fakeRates(): void
    {
        Http::fake([
            'api.biteship.com/v1/rates/couriers*' => Http::response(['success' => true, 'pricing' => [
                ['courier_code' => 'jne', 'courier_name' => 'JNE', 'courier_service_code' => 'reg', 'courier_service_name' => 'Reguler', 'price' => 12000, 'duration' => '2 - 3 days'],
                ['courier_code' => 'gojek', 'courier_name' => 'Gojek', 'courier_service_code' => 'instant', 'courier_service_name' => 'Instant', 'price' => 28000, 'duration' => '1 - 2 hours'],
                ['courier_code' => 'grab', 'courier_name' => 'Grab', 'courier_service_code' => 'instant', 'courier_service_name' => 'Instant', 'price' => 30000, 'duration' => '1 - 2 hours'],
            ]], 200),
        ]);
    }

    private function rates(Address $address, array $couriers)
    {
        return $this->actingAs($this->customer, 'sanctum')->postJson('/api/shipping/rates', [
            'destination' => $address->id,
            'weight' => 300,
            'couriers' => $couriers,
            'items' => [['product_id' => Product::factory()->create(['weight' => 300])->id, 'quantity' => 1]],
        ]);
    }

    private function ratesRequestPayload(): array
    {
        $sent = Http::recorded(fn ($request) => str_contains($request->url(), '/v1/rates/couriers'))->first();

        return $sent[0]->data();
    }

    // ---------------------------------------------------------------
    // Readiness policy
    // ---------------------------------------------------------------

    public function test_instant_couriers_need_both_the_switch_and_a_valid_store_pickup_point(): void
    {
        $policy = new InstantCourierPolicy;

        $this->instant(false);
        $this->assertFalse($policy->isEnabled());

        $this->instant(true, null);
        $this->assertFalse($policy->isEnabled());

        $this->instant(true, ['latitude' => 0.0, 'longitude' => 0.0]);
        $this->assertFalse($policy->isEnabled(), '0,0 is not in Indonesia');

        $this->instant(true, ['latitude' => 48.85, 'longitude' => 2.35]);
        $this->assertFalse($policy->isEnabled());

        $this->instant(true);
        $this->assertTrue($policy->isEnabled());
    }

    public function test_an_empty_origin_coordinate_in_the_env_file_is_missing_not_zero(): void
    {
        $_ENV['BITESHIP_ORIGIN_LATITUDE'] = $_ENV['BITESHIP_ORIGIN_LONGITUDE'] = '';

        try {
            $services = require config_path('services.php');
        } finally {
            unset($_ENV['BITESHIP_ORIGIN_LATITUDE'], $_ENV['BITESHIP_ORIGIN_LONGITUDE']);
        }

        $this->assertNull($services['biteship']['origin_latitude']);
        $this->assertNull($services['biteship']['origin_longitude']);
    }

    public function test_a_missing_store_pickup_point_is_reported_once_in_the_log(): void
    {
        Cache::forget('instant_courier_missing_origin_warning');
        $this->instant(true, null);
        $policy = new InstantCourierPolicy;

        $policy->isEnabled();
        $policy->isEnabled();

        $this->assertTrue(Cache::has('instant_courier_missing_origin_warning'));
    }

    // ---------------------------------------------------------------
    // Rates
    // ---------------------------------------------------------------

    public function test_gojek_and_grab_are_priced_from_the_store_and_the_customers_pin(): void
    {
        $this->instant(true);
        $this->fakeRates();

        $response = $this->rates($this->address(self::CUSTOMER_PIN), ['jne', 'gojek', 'grab'])->assertOk();

        $couriers = array_column($response->json('data'), 'courier');
        $this->assertEqualsCanonicalizing(['JNE', 'GOJEK', 'GRAB'], $couriers);
        $response->assertJsonPath('meta.instant_enabled', true)->assertJsonPath('meta.destination_has_pin', true);

        $payload = $this->ratesRequestPayload();
        $this->assertSame('jne,gojek,grab', $payload['couriers']);
        $this->assertEquals(self::CUSTOMER_PIN['latitude'], $payload['destination_latitude']);
        $this->assertEquals(self::CUSTOMER_PIN['longitude'], $payload['destination_longitude']);
        $this->assertEquals(self::STORE['latitude'], $payload['origin_latitude']);
        $this->assertEquals(self::STORE['longitude'], $payload['origin_longitude']);
    }

    public function test_an_address_without_a_pin_never_gets_instant_couriers_and_the_storefront_is_told_why(): void
    {
        $this->instant(true);
        $this->fakeRates();

        $this->rates($this->address(null), ['jne', 'gojek', 'grab'])
            ->assertOk()
            ->assertJsonPath('meta.instant_enabled', true)
            ->assertJsonPath('meta.destination_has_pin', false);

        $this->assertSame('jne', $this->ratesRequestPayload()['couriers']);
    }

    public function test_asking_only_for_instant_couriers_without_a_pin_returns_nothing_without_calling_biteship(): void
    {
        $this->instant(true);
        $this->fakeRates();

        $this->rates($this->address(null), ['gojek', 'grab'])->assertOk()->assertJsonPath('data', []);

        Http::assertNothingSent();
    }

    public function test_instant_couriers_stay_hidden_when_the_switch_is_off_or_the_store_point_is_missing(): void
    {
        $this->fakeRates();
        $address = $this->address(self::CUSTOMER_PIN);

        $this->instant(false);
        $this->rates($address, ['jne', 'gojek'])->assertOk()->assertJsonPath('meta.instant_enabled', false);
        $this->assertSame('jne', $this->ratesRequestPayload()['couriers']);

        Cache::flush();
        $this->instant(true, null);
        $this->rates($address, ['jne', 'grab'])->assertOk()->assertJsonPath('meta.instant_enabled', false);
        $this->assertSame('jne', $this->ratesRequestPayload()['couriers']);
    }

    public function test_a_legacy_pin_outside_indonesia_is_treated_as_no_pin(): void
    {
        $this->instant(true);
        $this->fakeRates();
        $address = $this->address(['latitude' => 0.0, 'longitude' => 0.0]);

        $this->rates($address, ['jne', 'gojek'])->assertOk()->assertJsonPath('meta.destination_has_pin', false);

        $this->assertArrayNotHasKey('destination_latitude', $this->ratesRequestPayload());
        $this->assertSame('jne', $this->ratesRequestPayload()['couriers']);
    }

    public function test_an_order_cannot_be_placed_with_gojek_for_an_address_without_a_pin(): void
    {
        $this->instant(true);
        $this->fakeRates();
        $product = Product::factory()->create(['price' => 100000, 'weight' => 300, 'stock' => 5, 'is_active' => true]);

        $this->actingAs($this->customer, 'sanctum')->postJson('/api/orders', [
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'address_id' => $this->address(null)->id,
            'courier' => 'gojek',
            'service' => 'instant',
        ])->assertUnprocessable()->assertJsonValidationErrors('shipping');

        $this->assertDatabaseCount('orders', 0);
        $this->assertEquals(5, $product->fresh()->stock);
    }

    public function test_an_order_with_gojek_is_priced_by_the_server_for_an_address_with_a_pin(): void
    {
        $this->instant(true);
        $this->fakeRates();
        $product = Product::factory()->create(['price' => 100000, 'weight' => 300, 'stock' => 5, 'is_active' => true]);

        $this->actingAs($this->customer, 'sanctum')->postJson('/api/orders', [
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'address_id' => $this->address(self::CUSTOMER_PIN)->id,
            'courier' => 'gojek',
            'service' => 'instant',
        ])->assertCreated()->assertJsonPath('data.shipping_cost', 28000)->assertJsonPath('data.shipping_courier', 'GOJEK');

        $this->assertEquals(self::CUSTOMER_PIN['latitude'], Order::firstOrFail()->shipping_address['latitude']);
    }

    // ---------------------------------------------------------------
    // Booking the courier
    // ---------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private function bookingPayload(string $courier, ?array $pin): array
    {
        return [
            'courier' => $courier,
            'service' => 'instant',
            'destination' => [
                'recipient_name' => 'Yoshi Kusuma',
                'phone' => '081211462862',
                'postal_code' => '16920',
                'address_line' => 'Perum Taman Bojong Lestari',
                'latitude' => $pin['latitude'] ?? null,
                'longitude' => $pin['longitude'] ?? null,
            ],
            'items' => [['product_name' => 'Body Oil', 'unit_price' => 275000, 'quantity' => 1, 'weight' => 320]],
        ];
    }

    private function fakeBooking(): void
    {
        Http::fake([
            'api.biteship.com/v1/orders' => Http::response([
                'id' => 'bit_instant_1',
                'status' => 'confirmed',
                'price' => 28000,
                'courier' => ['company' => 'gojek', 'type' => 'instant', 'waybill_id' => 'W-1', 'tracking_id' => 'T-1'],
            ], 200),
        ]);
    }

    public function test_an_instant_booking_sends_both_map_points_and_dispatches_immediately(): void
    {
        $this->instant(true);
        $this->fakeBooking();

        $result = app(BiteshipShipmentService::class)->createShipment($this->bookingPayload('gojek', self::CUSTOMER_PIN));

        $this->assertTrue($result['success']);
        Http::assertSent(function ($request): bool {
            return str_ends_with($request->url(), '/v1/orders')
                && $request['courier_company'] === 'gojek'
                && $request['courier_type'] === 'instant'
                && $request['delivery_type'] === 'now'
                && $request['origin_coordinate'] == self::STORE
                && $request['destination_coordinate'] == self::CUSTOMER_PIN;
        });
    }

    public function test_an_instant_booking_is_refused_without_a_usable_pin_and_never_reaches_biteship(): void
    {
        $this->instant(true);
        $this->fakeBooking();
        $service = app(BiteshipShipmentService::class);

        $this->assertFalse($service->createShipment($this->bookingPayload('gojek', null))['success']);
        $this->assertFalse($service->createShipment($this->bookingPayload('grab', ['latitude' => 0.0, 'longitude' => 0.0]))['success']);

        Http::assertNothingSent();
    }

    public function test_an_instant_booking_is_refused_when_instant_delivery_is_not_ready(): void
    {
        $this->instant(true, null);
        $this->fakeBooking();

        $this->assertFalse(app(BiteshipShipmentService::class)->createShipment($this->bookingPayload('gojek', self::CUSTOMER_PIN))['success']);

        Http::assertNothingSent();
    }

    // ---------------------------------------------------------------
    // Address pins
    // ---------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private function addressPayload(array $overrides = []): array
    {
        return [
            'name' => 'Yoshi Kusuma',
            'phone' => '081211462862',
            'province' => 'Jawa Barat',
            'city' => 'Bogor',
            'district' => 'Bojonggede',
            'postal_code' => '16920',
            'address' => 'Perum Taman Bojong Lestari',
            ...$overrides,
        ];
    }

    public function test_an_address_may_be_saved_without_a_pin_or_with_a_pin_in_indonesia(): void
    {
        $this->actingAs($this->customer, 'sanctum')->postJson('/api/addresses', $this->addressPayload())->assertCreated();
        $this->actingAs($this->customer, 'sanctum')->postJson('/api/addresses', $this->addressPayload(self::CUSTOMER_PIN))->assertCreated();
        $this->actingAs($this->customer, 'sanctum')->postJson('/api/addresses', $this->addressPayload(['latitude' => -8.65, 'longitude' => 115.21]))->assertCreated();
    }

    public function test_a_pin_must_be_complete_and_inside_indonesia(): void
    {
        $this->actingAs($this->customer, 'sanctum')->postJson('/api/addresses', $this->addressPayload(['latitude' => -6.49]))
            ->assertUnprocessable()->assertJsonValidationErrors('longitude');
        $this->actingAs($this->customer, 'sanctum')->postJson('/api/addresses', $this->addressPayload(['latitude' => 0, 'longitude' => 0]))
            ->assertUnprocessable()->assertJsonValidationErrors('latitude');
        $this->actingAs($this->customer, 'sanctum')->postJson('/api/addresses', $this->addressPayload(['latitude' => 48.85, 'longitude' => 2.35]))
            ->assertUnprocessable()->assertJsonValidationErrors('latitude');

        $this->assertDatabaseCount('addresses', 0);
    }

    public function test_the_old_default_jakarta_placeholder_pin_is_cleared_but_real_pins_are_kept(): void
    {
        $placeholder = $this->address(['latitude' => -6.2088, 'longitude' => 106.8456]);
        $real = $this->address(self::CUSTOMER_PIN);
        $nearMonas = $this->address(['latitude' => -6.2101, 'longitude' => 106.8456]);

        (require database_path('migrations/2026_10_08_191323_clear_placeholder_address_coordinates.php'))->up();

        $this->assertNull($placeholder->fresh()->latitude);
        $this->assertNull($placeholder->fresh()->longitude);
        $this->assertEquals(self::CUSTOMER_PIN['latitude'], $real->fresh()->latitude);
        $this->assertEquals(-6.2101, $nearMonas->fresh()->latitude);
    }

    // ---------------------------------------------------------------
    // No driver found
    // ---------------------------------------------------------------

    private function processingOrderWithBooking(string $shipmentStatus = 'processing', string $biteshipOrderId = 'bit_instant_1'): Order
    {
        $product = Product::factory()->create(['stock' => 8]);
        $order = Order::factory()->create(['user_id' => $this->customer->id, 'status' => 'PROCESSING', 'shipping_courier' => 'GOJEK', 'shipping_service' => 'INSTANT']);
        OrderItem::factory()->create(['order_id' => $order->id, 'product_id' => $product->id, 'quantity' => 2]);
        Shipment::create([
            'order_id' => $order->id,
            'courier' => 'GOJEK',
            'service' => 'INSTANT',
            'status' => $shipmentStatus,
            'biteship_order_id' => $biteshipOrderId,
            'biteship_waybill_id' => 'W-'.$biteshipOrderId,
            'tracking_number' => 'W-'.$biteshipOrderId,
        ]);

        return $order->fresh(['shipment', 'orderItems']);
    }

    private function webhook(string $status, string $biteshipOrderId = 'bit_instant_1')
    {
        return $this->withHeader('X-Biteship-Signature', 'test-webhook-secret')
            ->postJson('/api/shipping/webhook/biteship', ['event' => 'order.status', 'status' => $status, 'order_id' => $biteshipOrderId]);
    }

    public function test_no_driver_found_is_recorded_flagged_and_keeps_the_order_in_processing(): void
    {
        $order = $this->processingOrderWithBooking();

        $this->webhook('courier_not_found')->assertOk();

        $this->assertDatabaseHas('shipments', ['order_id' => $order->id, 'status' => 'courier_not_found']);
        $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => 'PROCESSING']);
        $this->assertDatabaseHas('order_audit_logs', ['order_id' => $order->id, 'action' => 'COURIER_NOT_FOUND']);
        $this->assertContains('rebook_courier', $order->fresh('shipment')->allowed_actions);

        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin, 'sanctum')->getJson('/api/admin/operations/alerts')->assertJsonPath('data.shipments_without_courier', 1);
    }

    public function test_a_repeated_no_driver_event_is_not_logged_twice_and_never_overrides_a_shipped_parcel(): void
    {
        $order = $this->processingOrderWithBooking();
        $this->webhook('courier_not_found')->assertOk();
        $this->webhook('courier_not_found')->assertOk();
        $this->assertSame(1, $order->auditLogs()->where('action', 'COURIER_NOT_FOUND')->count());

        $shipped = $this->processingOrderWithBooking('shipped', 'bit_shipped');
        $this->webhook('courier_not_found', 'bit_shipped')->assertOk();

        $this->assertDatabaseHas('shipments', ['order_id' => $shipped->id, 'status' => 'shipped']);
    }

    public function test_admin_books_a_new_courier_after_no_driver_was_found(): void
    {
        Queue::fake();
        $order = $this->processingOrderWithBooking('courier_not_found');
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin, 'sanctum')->postJson("/api/admin/orders/{$order->id}/rebook-courier")->assertOk();

        $this->assertDatabaseHas('shipments', [
            'order_id' => $order->id,
            'status' => 'pending',
            'biteship_order_id' => null,
            'biteship_waybill_id' => null,
            'tracking_number' => null,
        ]);
        Queue::assertPushed(CreateBiteshipShipmentJob::class, fn (CreateBiteshipShipmentJob $job): bool => $job->orderId === $order->id);
        $this->assertDatabaseHas('order_audit_logs', ['order_id' => $order->id, 'action' => 'COURIER_REBOOK_REQUESTED', 'admin_id' => $admin->id]);
    }

    public function test_rebooking_is_refused_unless_the_booking_really_found_no_driver_and_only_admins_may_do_it(): void
    {
        Queue::fake();
        $healthy = $this->processingOrderWithBooking('processing');
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin, 'sanctum')->postJson("/api/admin/orders/{$healthy->id}/rebook-courier")
            ->assertUnprocessable()->assertJsonValidationErrors('shipment');
        $this->assertDatabaseHas('shipments', ['order_id' => $healthy->id, 'biteship_order_id' => 'bit_instant_1']);

        $stuck = $this->processingOrderWithBooking('courier_not_found', 'bit_stuck');
        $this->actingAs($this->customer, 'sanctum')->postJson("/api/admin/orders/{$stuck->id}/rebook-courier")->assertForbidden();

        Queue::assertNothingPushed();
    }

    public function test_cancelling_an_order_with_no_driver_restores_stock_without_contacting_biteship(): void
    {
        Http::preventStrayRequests();
        $order = $this->processingOrderWithBooking('courier_not_found');
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin, 'sanctum')->postJson("/api/admin/orders/{$order->id}/cancel", ['reason' => 'shipping_issue'])->assertOk();

        $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => 'CANCELLED']);
        $this->assertDatabaseHas('shipments', ['order_id' => $order->id, 'status' => 'cancelled']);
        $this->assertDatabaseHas('products', ['id' => $order->orderItems[0]->product_id, 'stock' => 10]);
        Http::assertNothingSent();
    }
}
