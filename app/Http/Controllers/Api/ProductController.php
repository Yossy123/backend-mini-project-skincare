<?php

namespace App\Http\Controllers\Api;

use App\Filters\ProductQueryFilter;
use App\Http\Controllers\Controller;
use App\Http\Requests\ProductFilterRequest;
use App\Http\Resources\ProductResource;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ProductController extends Controller
{
    /**
     * List paginated active products with optional search, category, and sorting filters.
     */
    public function index(ProductFilterRequest $request, ProductQueryFilter $filter): AnonymousResourceCollection
    {
        $perPage = (int) $request->query('per_page', 12);
        $perPage = max(1, min(100, $perPage));

        $products = $filter->apply(Product::query(), $request)
            ->paginate($perPage);

        return ProductResource::collection($products);
    }

    /**
     * Show a single product by slug. Returns 404 if inactive or not found.
     */
    public function show(string $slug): JsonResponse|ProductResource
    {
        $product = Product::query()
            ->where('slug', $slug)
            ->where('is_active', true)
            ->with('category')
            ->first();

        if (! $product) {
            return response()->json([
                'message' => 'Product not found.',
            ], 404);
        }

        return new ProductResource($product);
    }
}
