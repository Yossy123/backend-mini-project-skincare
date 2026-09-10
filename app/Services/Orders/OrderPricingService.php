<?php

namespace App\Services\Orders;

use App\Models\Product;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class OrderPricingService
{
    /**
     * Validate product active statuses, stock availability, and compute subtotal and weight breakdown.
     *
     * @param  Collection<int, Product>  $products  Keyed by product ID
     * @param  array<int, int>  $quantitiesByProductId
     * @return array{
     *     order_items_data: array<int, array{
     *         product_model: Product,
     *         product_id: int,
     *         product_name: string,
     *         unit_price: float,
     *         quantity: int,
     *         weight: int,
     *         subtotal: float
     *     }>,
     *     subtotal: float,
     *     total_weight: int
     * }
     *
     * @throws ValidationException
     */
    public function calculatePricingAndValidate(Collection $products, array $quantitiesByProductId): array
    {
        $orderItemsData = [];
        $subtotal = 0.0;
        $totalWeight = 0;

        foreach ($quantitiesByProductId as $productId => $quantity) {
            /** @var Product|null $product */
            $product = $products->get($productId);

            if (! $product) {
                throw ValidationException::withMessages([
                    'items' => ["Product with ID {$productId} was not found."],
                ]);
            }

            if (! $product->is_active) {
                throw ValidationException::withMessages([
                    'items' => ["Product '{$product->name}' is no longer active."],
                ]);
            }

            if ($product->stock < $quantity) {
                throw ValidationException::withMessages([
                    'items' => [
                        "Insufficient stock for '{$product->name}'. Requested: {$quantity}, Available in stock: {$product->stock}.",
                    ],
                ]);
            }

            $unitPrice = (float) $product->price;
            $itemWeight = (int) $product->weight;
            $lineSubtotal = $unitPrice * $quantity;
            $lineWeight = $itemWeight * $quantity;

            $subtotal += $lineSubtotal;
            $totalWeight += $lineWeight;

            $orderItemsData[] = [
                'product_model' => $product,
                'product_id' => $product->id,
                'product_name' => $product->name, // Frozen Snapshot
                'unit_price' => $unitPrice,       // Frozen Snapshot
                'quantity' => $quantity,
                'weight' => $itemWeight,
                'subtotal' => $lineSubtotal,       // Frozen Snapshot
            ];
        }

        return [
            'order_items_data' => $orderItemsData,
            'subtotal' => $subtotal,
            'total_weight' => $totalWeight,
        ];
    }
}
