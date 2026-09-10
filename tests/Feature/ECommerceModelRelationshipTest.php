<?php

namespace Tests\Feature;

use App\Models\Address;
use App\Models\Category;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Shipment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ECommerceModelRelationshipTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test Category and Product relationship.
     */
    public function test_category_has_many_products_and_product_belongs_to_category(): void
    {
        $category = Category::factory()->create(['name' => 'Skincare', 'slug' => 'skincare']);
        $product1 = Product::factory()->create(['category_id' => $category->id]);
        $product2 = Product::factory()->create(['category_id' => $category->id]);

        $this->assertCount(2, $category->products);
        $this->assertTrue($category->products->contains($product1));
        $this->assertTrue($category->products->contains($product2));
        $this->assertEquals($category->id, $product1->category->id);
    }

    /**
     * Test User and Address relationship.
     */
    public function test_user_has_many_addresses_and_address_belongs_to_user(): void
    {
        $user = User::factory()->create();
        $address1 = Address::factory()->create(['user_id' => $user->id, 'is_default' => true]);
        $address2 = Address::factory()->create(['user_id' => $user->id, 'is_default' => false]);

        $this->assertCount(2, $user->addresses);
        $this->assertTrue($user->addresses->contains($address1));
        $this->assertEquals($user->id, $address1->user->id);
        $this->assertTrue($address1->is_default);
        $this->assertFalse($address2->is_default);
    }

    /**
     * Test User and Order relationship.
     */
    public function test_user_has_many_orders_and_order_belongs_to_user(): void
    {
        $user = User::factory()->create();
        $order = Order::factory()->create(['user_id' => $user->id]);

        $this->assertCount(1, $user->orders);
        $this->assertEquals($user->id, $order->user->id);
    }

    /**
     * Test Order relationships: OrderItems, Payment (1:1), Shipment (1:1).
     */
    public function test_order_has_many_items_has_one_payment_and_has_one_shipment(): void
    {
        $user = User::factory()->create();
        $category = Category::factory()->create();
        $product = Product::factory()->create(['category_id' => $category->id, 'price' => 250000.00]);

        $order = Order::factory()->create([
            'user_id' => $user->id,
            'subtotal' => 500000.00,
            'shipping_cost' => 20000.00,
            'total' => 520000.00,
        ]);

        $orderItem = OrderItem::factory()->create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'product_name' => $product->name,
            'unit_price' => 250000.00,
            'quantity' => 2,
            'subtotal' => 500000.00,
        ]);

        $payment = Payment::factory()->create([
            'order_id' => $order->id,
            'amount' => 520000.00,
            'status' => 'success',
            'paid_at' => now(),
        ]);

        $shipment = Shipment::factory()->create([
            'order_id' => $order->id,
            'status' => 'shipped',
        ]);

        // Assert relationships
        $this->assertCount(1, $order->orderItems);
        $this->assertEquals($order->id, $orderItem->order->id);
        $this->assertEquals($product->id, $orderItem->product->id);

        $this->assertNotNull($order->payment);
        $this->assertEquals($payment->id, $order->payment->id);
        $this->assertEquals($order->id, $payment->order->id);

        $this->assertNotNull($order->shipment);
        $this->assertEquals($shipment->id, $order->shipment->id);
        $this->assertEquals($order->id, $shipment->order->id);
    }

    /**
     * Test Data Rule: OrderItem unit_price & product_name are snapshots and independent of future Product price updates.
     */
    public function test_order_item_price_is_independent_of_future_product_price_changes(): void
    {
        $category = Category::factory()->create();
        $product = Product::factory()->create([
            'category_id' => $category->id,
            'name' => 'Original Luxury Serum',
            'price' => 300000.00,
        ]);

        $order = Order::factory()->create([
            'subtotal' => 300000.00,
            'shipping_cost' => 15000.00,
            'total' => 315000.00,
        ]);

        $orderItem = OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'product_name' => $product->name,
            'unit_price' => $product->price,
            'quantity' => 1,
            'subtotal' => $product->price,
        ]);

        // Product price is subsequently updated in the catalog (e.g., inflation or discount)
        $product->update([
            'name' => 'Renamed Luxury Serum Pro',
            'price' => 450000.00,
        ]);

        // Refresh the order item from database
        $orderItem->refresh();

        // Snapshot in order_items must remain unaffected
        $this->assertEquals('300000.00', (string) $orderItem->unit_price);
        $this->assertEquals('300000.00', (string) $orderItem->subtotal);
        $this->assertEquals('Original Luxury Serum', $orderItem->product_name);

        // Product itself reflects the new price
        $this->assertEquals('450000.00', (string) $product->fresh()->price);
    }

    /**
     * Test Product deletion preserves OrderItem historical snapshot (nullOnDelete).
     */
    public function test_deleting_product_preserves_order_item_history_with_null_product_id(): void
    {
        $category = Category::factory()->create();
        $product = Product::factory()->create([
            'category_id' => $category->id,
            'name' => 'Limited Edition Mist',
            'price' => 200000.00,
        ]);

        $order = Order::factory()->create();
        $orderItem = OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'product_name' => $product->name,
            'unit_price' => $product->price,
            'quantity' => 1,
            'subtotal' => $product->price,
        ]);

        // Delete the product from catalog
        $product->delete();

        $orderItem->refresh();
        $this->assertNull($orderItem->product_id);
        $this->assertNull($orderItem->product);
        $this->assertEquals('Limited Edition Mist', $orderItem->product_name);
        $this->assertEquals('200000.00', (string) $orderItem->unit_price);
    }

    /**
     * Test Attribute Casts: Decimal precision, integer weight/stock, boolean, JSON serialization.
     */
    public function test_attribute_casts_and_json_serialization(): void
    {
        $category = Category::factory()->create();
        $product = Product::factory()->create([
            'category_id' => $category->id,
            'price' => 189500.50,
            'weight' => 250,
            'stock' => 100,
            'is_active' => true,
        ]);

        $this->assertIsString($product->price);
        $this->assertEquals('189500.50', $product->price);
        $this->assertIsInt($product->weight);
        $this->assertEquals(250, $product->weight);
        $this->assertIsInt($product->stock);
        $this->assertEquals(100, $product->stock);
        $this->assertIsBool($product->is_active);

        $addressData = [
            'recipient_name' => 'Jane Doe',
            'phone' => '+628123456789',
            'city' => 'Jakarta Selatan',
        ];

        $order = Order::factory()->create([
            'shipping_address' => $addressData,
        ]);

        $this->assertIsArray($order->shipping_address);
        $this->assertEquals('Jane Doe', $order->shipping_address['recipient_name']);
    }
}
