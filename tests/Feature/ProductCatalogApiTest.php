<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductCatalogApiTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test GET /api/categories returns active categories with products count.
     */
    public function test_can_list_categories(): void
    {
        $activeCat = Category::factory()->create(['name' => 'Skincare', 'slug' => 'skincare', 'is_active' => true]);
        $inactiveCat = Category::factory()->create(['name' => 'Archived', 'slug' => 'archived', 'is_active' => false]);
        Product::factory()->count(3)->create(['category_id' => $activeCat->id, 'is_active' => true]);

        $response = $this->getJson('/api/categories');

        $response->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.slug', 'skincare')
            ->assertJsonPath('data.0.products_count', 3);
    }

    /**
     * Test GET /api/categories/{slug} returns category or 404.
     */
    public function test_can_show_category_by_slug(): void
    {
        $category = Category::factory()->create(['name' => 'Makeup', 'slug' => 'makeup']);

        $response = $this->getJson('/api/categories/makeup');
        $response->assertStatus(200)
            ->assertJsonPath('data.slug', 'makeup')
            ->assertJsonPath('data.name', 'Makeup');

        $notFound = $this->getJson('/api/categories/non-existent');
        $notFound->assertStatus(404);
    }

    /**
     * Test GET /api/products returns paginated active products.
     */
    public function test_can_list_paginated_active_products(): void
    {
        $category = Category::factory()->create();
        Product::factory()->count(15)->create(['category_id' => $category->id, 'is_active' => true]);
        Product::factory()->count(3)->create(['category_id' => $category->id, 'is_active' => false]);

        $response = $this->getJson('/api/products?per_page=10');

        $response->assertStatus(200)
            ->assertJsonCount(10, 'data')
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'id',
                        'name',
                        'slug',
                        'price',
                        'formatted_price',
                        'weight',
                        'stock',
                        'category',
                    ],
                ],
                'links',
                'meta',
            ])
            ->assertJsonPath('meta.total', 15);
    }

    /**
     * Test searching products by name and description.
     */
    public function test_can_search_products(): void
    {
        $category = Category::factory()->create();
        $match = Product::factory()->create([
            'category_id' => $category->id,
            'name' => 'Radiance Vitamin C Serum',
            'description' => 'Brightening antioxidant formulation',
        ]);

        $other = Product::factory()->create([
            'category_id' => $category->id,
            'name' => 'Gentle Hydrating Cleanser',
            'description' => 'Foaming face wash',
        ]);

        $response = $this->getJson('/api/products?search=Vitamin+C');

        $response->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $match->id);
    }

    /**
     * Test filtering products by category slug.
     */
    public function test_can_filter_products_by_category(): void
    {
        $skincare = Category::factory()->create(['name' => 'Skincare', 'slug' => 'skincare']);
        $makeup = Category::factory()->create(['name' => 'Makeup', 'slug' => 'makeup']);

        $p1 = Product::factory()->create(['category_id' => $skincare->id]);
        $p2 = Product::factory()->create(['category_id' => $makeup->id]);

        $response = $this->getJson('/api/products?category=skincare');

        $response->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $p1->id);

        $categoryResponse = $this->getJson('/api/categories/skincare/products');
        $categoryResponse->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $p1->id);
    }

    /**
     * Test sorting products by price ascending and descending.
     */
    public function test_can_sort_products_by_price(): void
    {
        $category = Category::factory()->create();
        $cheap = Product::factory()->create(['category_id' => $category->id, 'price' => 100000.00]);
        $expensive = Product::factory()->create(['category_id' => $category->id, 'price' => 500000.00]);

        $asc = $this->getJson('/api/products?sort=price_asc');
        $asc->assertStatus(200);
        $this->assertEquals($cheap->id, $asc->json('data.0.id'));

        $desc = $this->getJson('/api/products?sort=price_desc');
        $desc->assertStatus(200);
        $this->assertEquals($expensive->id, $desc->json('data.0.id'));
    }

    /**
     * Test GET /api/products/{slug} returns product details with category.
     */
    public function test_can_show_product_by_slug(): void
    {
        $category = Category::factory()->create(['name' => 'Fragrance', 'slug' => 'fragrance']);
        $product = Product::factory()->create([
            'category_id' => $category->id,
            'name' => 'Maison Rose Parfum',
            'slug' => 'maison-rose-parfum',
            'is_active' => true,
        ]);

        $response = $this->getJson('/api/products/maison-rose-parfum');

        $response->assertStatus(200)
            ->assertJsonPath('data.slug', 'maison-rose-parfum')
            ->assertJsonPath('data.category.slug', 'fragrance');
    }

    /**
     * Test invalid or inactive product slug returns 404.
     */
    public function test_invalid_or_inactive_product_returns_404(): void
    {
        $category = Category::factory()->create();
        Product::factory()->create([
            'category_id' => $category->id,
            'slug' => 'inactive-product',
            'is_active' => false,
        ]);

        $response1 = $this->getJson('/api/products/inactive-product');
        $response1->assertStatus(404);

        $response2 = $this->getJson('/api/products/does-not-exist');
        $response2->assertStatus(404);
    }
}
