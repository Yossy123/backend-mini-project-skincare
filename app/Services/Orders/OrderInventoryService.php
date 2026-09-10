<?php

namespace App\Services\Orders;

use App\Models\Product;
use Illuminate\Support\Collection;

class OrderInventoryService
{
    /**
     * Lock products for update in database to prevent race conditions.
     *
     * @param  array<int, int>  $productIds
     * @return Collection<int, Product> Keyed by product ID
     */
    public function lockProductsForOrder(array $productIds): Collection
    {
        return Product::whereIn('id', $productIds)
            ->lockForUpdate()
            ->get()
            ->keyBy('id');
    }

    /**
     * Deduct reserved quantity from product inventory.
     */
    public function deductStock(Product $product, int $quantity): void
    {
        $product->decrement('stock', $quantity);
    }
}
