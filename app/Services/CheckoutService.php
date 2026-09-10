<?php

namespace App\Services;

use App\Http\Resources\AddressResource;
use App\Models\Address;
use App\Models\Product;
use App\Models\User;
use Illuminate\Validation\ValidationException;

class CheckoutService
{
    /**
     * Validate checkout items against database records and calculate authoritative totals.
     *
     * @param  array<int, array{product_id: int, quantity: int}>  $itemsPayload
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    public function validateAndCalculate(User $user, array $itemsPayload, ?int $addressId = null): array
    {
        if (empty($itemsPayload)) {
            throw ValidationException::withMessages([
                'items' => ['Your shopping cart is empty.'],
            ]);
        }

        // Group quantities by product_id in case duplicates were sent
        $quantitiesByProductId = [];
        foreach ($itemsPayload as $item) {
            $productId = (int) $item['product_id'];
            $qty = max(1, (int) $item['quantity']);
            $quantitiesByProductId[$productId] = ($quantitiesByProductId[$productId] ?? 0) + $qty;
        }

        $productIds = array_keys($quantitiesByProductId);
        $products = Product::with('category')
            ->whereIn('id', $productIds)
            ->get()
            ->keyBy('id');

        $validatedItems = [];
        $subtotal = 0.0;
        $totalWeight = 0;
        $totalItemsCount = 0;

        foreach ($quantitiesByProductId as $productId => $quantity) {
            $product = $products->get($productId);

            if (! $product) {
                throw ValidationException::withMessages([
                    'items' => ["Product with ID {$productId} was not found."],
                ]);
            }

            if (! $product->is_active) {
                throw ValidationException::withMessages([
                    'items' => ["Product '{$product->name}' is currently unavailable."],
                ]);
            }

            if ($product->stock < $quantity) {
                throw ValidationException::withMessages([
                    'items' => [
                        "Insufficient stock for '{$product->name}'. Requested: {$quantity}, available: {$product->stock}.",
                    ],
                ]);
            }

            $unitPrice = (float) $product->price;
            $itemWeight = (int) $product->weight;
            $lineSubtotal = $unitPrice * $quantity;
            $lineWeight = $itemWeight * $quantity;

            $subtotal += $lineSubtotal;
            $totalWeight += $lineWeight;
            $totalItemsCount += $quantity;

            $validatedItems[] = [
                'product_id' => $product->id,
                'name' => $product->name,
                'slug' => $product->slug,
                'image' => $product->image,
                'category' => $product->category ? [
                    'id' => $product->category->id,
                    'name' => $product->category->name,
                    'slug' => $product->category->slug,
                ] : null,
                'price' => $unitPrice,
                'formatted_price' => 'Rp '.number_format($unitPrice, 0, ',', '.'),
                'weight' => $itemWeight,
                'stock' => $product->stock,
                'quantity' => $quantity,
                'line_subtotal' => $lineSubtotal,
                'formatted_line_subtotal' => 'Rp '.number_format($lineSubtotal, 0, ',', '.'),
            ];
        }

        // Validate Selected Address
        $selectedAddress = null;
        if ($addressId !== null) {
            $address = Address::where('id', $addressId)
                ->where('user_id', $user->id)
                ->first();

            if (! $address) {
                throw ValidationException::withMessages([
                    'address_id' => ['The selected delivery address is invalid or does not belong to your account.'],
                ]);
            }

            $selectedAddress = new AddressResource($address);
        } else {
            // Auto-fallback to default address if none explicitly passed
            $defaultAddress = $user->addresses()->where('is_default', true)->first();
            if ($defaultAddress) {
                $selectedAddress = new AddressResource($defaultAddress);
            }
        }

        $formattedWeight = $totalWeight >= 1000
            ? number_format($totalWeight / 1000, 2, '.', '').' kg'
            : "{$totalWeight} g";

        return [
            'items' => $validatedItems,
            'summary' => [
                'subtotal' => $subtotal,
                'formatted_subtotal' => 'Rp '.number_format($subtotal, 0, ',', '.'),
                'total_weight' => $totalWeight,
                'formatted_total_weight' => $formattedWeight,
                'total_items' => $totalItemsCount,
            ],
            'shipping_address' => $selectedAddress,
            'is_valid' => true,
        ];
    }
}
