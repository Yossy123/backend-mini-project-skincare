<?php

namespace App\Http\Controllers\Api;

use App\Filters\ProductQueryFilter;
use App\Http\Controllers\Controller;
use App\Http\Requests\ProductFilterRequest;
use App\Http\Resources\CategoryResource;
use App\Http\Resources\ProductResource;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class CategoryController extends Controller
{
    /**
     * List all active categories with product counts.
     */
    public function index(): AnonymousResourceCollection
    {
        $categories = Category::query()
            ->where('is_active', true)
            ->withCount(['products' => function ($query) {
                $query->where('is_active', true);
            }])
            ->orderBy('name', 'asc')
            ->get();

        return CategoryResource::collection($categories);
    }

    /**
     * Get a single category by slug.
     */
    public function show(string $slug): JsonResponse|CategoryResource
    {
        $category = Category::query()
            ->where('slug', $slug)
            ->where('is_active', true)
            ->withCount(['products' => function ($query) {
                $query->where('is_active', true);
            }])
            ->first();

        if (! $category) {
            return response()->json([
                'message' => 'Category not found.',
            ], 404);
        }

        return new CategoryResource($category);
    }

    /**
     * List products belonging to a specific category slug.
     */
    public function products(ProductFilterRequest $request, string $slug, ProductQueryFilter $filter): JsonResponse|AnonymousResourceCollection
    {
        $category = Category::query()
            ->where('slug', $slug)
            ->where('is_active', true)
            ->first();

        if (! $category) {
            return response()->json([
                'message' => 'Category not found.',
            ], 404);
        }

        $perPage = (int) $request->query('per_page', 12);
        $perPage = max(1, min(100, $perPage));

        $products = $filter->apply(Product::query(), $request, $category->slug)
            ->paginate($perPage);

        return ProductResource::collection($products);
    }
}
