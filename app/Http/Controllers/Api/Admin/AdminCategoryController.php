<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\AdminCategoryRequest;
use App\Models\Category;
use App\Services\AdminCategoryService;
use Illuminate\Http\JsonResponse;

class AdminCategoryController extends Controller
{
    public function __construct(
        protected AdminCategoryService $categoryService
    ) {}

    /**
     * Display all categories with products count.
     */
    public function index(): JsonResponse
    {
        $categories = $this->categoryService->listCategories();

        return response()->json([
            'data' => $categories,
        ], 200);
    }

    /**
     * Display a single category.
     */
    public function show(int $id): JsonResponse
    {
        $category = Category::withCount('products')->findOrFail($id);

        return response()->json([
            'data' => $category,
        ], 200);
    }

    /**
     * Store a newly created category.
     */
    public function store(AdminCategoryRequest $request): JsonResponse
    {
        $category = $this->categoryService->createCategory($request->validated());

        return response()->json([
            'message' => "Category '{$category->name}' created successfully.",
            'data' => $category->loadCount('products'),
        ], 201);
    }

    /**
     * Update an existing category.
     */
    public function update(int $id, AdminCategoryRequest $request): JsonResponse
    {
        $category = Category::findOrFail($id);

        $updated = $this->categoryService->updateCategory($category, $request->validated());

        return response()->json([
            'message' => "Category '{$updated->name}' updated successfully.",
            'data' => $updated,
        ], 200);
    }

    /**
     * Toggle category activation.
     */
    public function toggle(int $id): JsonResponse
    {
        $category = Category::findOrFail($id);
        $updated = $this->categoryService->toggleActivation($category);

        $statusText = $updated->is_active ? 'activated' : 'deactivated';

        return response()->json([
            'message' => "Category '{$updated->name}' is now {$statusText}.",
            'data' => $updated,
        ], 200);
    }

    /**
     * Delete a category if safe (no attached products).
     */
    public function destroy(int $id): JsonResponse
    {
        $category = Category::findOrFail($id);
        $name = $category->name;

        $this->categoryService->deleteCategory($category);

        return response()->json([
            'message' => "Category '{$name}' has been deleted.",
        ], 200);
    }
}
