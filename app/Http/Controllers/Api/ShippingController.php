<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ShippingRateRequest;
use App\Models\Product;
use App\Services\ShippingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Throwable;

class ShippingController extends Controller
{
    public function __construct(
        protected ShippingService $shippingService
    ) {}

    /**
     * Calculate and return normalized shipping rates from supported couriers via Biteship.
     */
    public function rates(ShippingRateRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $rateItems = null;
        $weight = (int) $validated['weight'];

        if (! empty($validated['items'])) {
            $quantities = collect($validated['items'])
                ->groupBy('product_id')
                ->map(fn ($items) => $items->sum('quantity'));
            $products = Product::whereIn('id', $quantities->keys())->get()->keyBy('id');
            $weight = 0;
            $rateItems = [];

            foreach ($quantities as $productId => $quantity) {
                $product = $products->get($productId);
                if (! $product || ! $product->is_active) {
                    return response()->json(['message' => 'One or more products are unavailable.'], 422);
                }

                $weight += (int) $product->weight * (int) $quantity;
                $rateItems[] = [
                    'name' => $product->name,
                    'description' => $product->name,
                    'value' => (float) $product->price,
                    'weight' => (int) $product->weight,
                    'quantity' => (int) $quantity,
                ];
            }
        }

        try {
            $rates = $this->shippingService->getShippingRates(
                $validated['destination'],
                $weight,
                $validated['couriers'] ?? null,
                $request->user(),
                $rateItems
            );
        } catch (ValidationException $e) {
            throw $e;
        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'message' => $e->getMessage() ?: 'Shipping rates are currently unavailable. Please try again later.',
            ], 502);
        }

        return response()->json([
            'data' => $rates,
        ], 200);
    }

    /**
     * Search domestic destination locations using Biteship areas API.
     */
    public function destinations(Request $request): JsonResponse
    {
        $search = (string) $request->query('search', '');
        $destinations = $this->shippingService->searchAreas($search);

        return response()->json([
            'data' => $destinations,
        ], 200);
    }
}
