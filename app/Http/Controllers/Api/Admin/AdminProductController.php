<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\AdminProductRequest;
use App\Http\Requests\AdminStockAdjustmentRequest;
use App\Http\Resources\ProductResource;
use App\Models\Product;
use App\Services\AdminProductService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminProductController extends Controller
{
    public function __construct(
        protected AdminProductService $productService
    ) {}

    /**
     * Display a listing of products with back office filtering and pagination.
     */
    public function index(Request $request): JsonResponse
    {
        $filters = $request->only([
            'search',
            'category_id',
            'is_active',
            'stock_status',
        ]);

        $perPage = (int) $request->get('per_page', 15);
        $products = $this->productService->listProducts($filters, $perPage);

        return response()->json($products, 200);
    }

    /**
     * Display a single product.
     */
    public function show(int $id): JsonResponse
    {
        $product = Product::with('category')->findOrFail($id);

        return response()->json([
            'data' => new ProductResource($product),
        ], 200);
    }

    /**
     * Store a newly created product.
     */
    public function store(AdminProductRequest $request): JsonResponse
    {
        $product = $this->productService->createProduct(
            $request->validated(),
            $request->file('image_file')
        );

        return response()->json([
            'message' => "Product '{$product->name}' created successfully.",
            'data' => new ProductResource($product->load('category')),
        ], 201);
    }

    /**
     * Update an existing product master record.
     */
    public function update(int $id, AdminProductRequest $request): JsonResponse
    {
        $product = Product::findOrFail($id);

        $updatedProduct = $this->productService->updateProduct(
            $product,
            $request->validated(),
            $request->file('image_file')
        );

        return response()->json([
            'message' => "Product '{$updatedProduct->name}' updated successfully.",
            'data' => new ProductResource($updatedProduct),
        ], 200);
    }

    /**
     * Toggle product activation status.
     */
    public function toggle(int $id): JsonResponse
    {
        $product = Product::findOrFail($id);
        $updated = $this->productService->toggleActivation($product);

        $statusText = $updated->is_active ? 'activated' : 'deactivated';

        return response()->json([
            'message' => "Product '{$updated->name}' is now {$statusText}.",
            'data' => new ProductResource($updated),
        ], 200);
    }

    /**
     * Controlled inventory adjustment for product stock.
     */
    public function adjustStock(int $id, AdminStockAdjustmentRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $updated = $this->productService->adjustStock(
            $id,
            $validated['type'],
            (int) $validated['amount'],
            $validated['reason'] ?? null
        );

        return response()->json([
            'message' => "Stock for '{$updated->name}' updated to {$updated->stock} units.",
            'data' => new ProductResource($updated),
        ], 200);
    }
}
