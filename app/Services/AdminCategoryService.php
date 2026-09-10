<?php

namespace App\Services;

use App\Models\Category;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AdminCategoryService
{
    /**
     * Get all categories with products count.
     *
     * @return Collection<int, Category>
     */
    public function listCategories(): Collection
    {
        return Category::withCount('products')
            ->orderBy('name', 'asc')
            ->get();
    }

    /**
     * Create a new category.
     *
     * @param  array<string, mixed>  $data
     */
    public function createCategory(array $data): Category
    {
        $slug = ! empty($data['slug']) ? Str::slug($data['slug']) : Str::slug($data['name']);
        $slug = $this->ensureUniqueSlug($slug);

        return Category::create([
            'name' => trim($data['name']),
            'slug' => $slug,
            'description' => $data['description'] ?? null,
            'is_active' => isset($data['is_active']) ? (bool) $data['is_active'] : true,
        ]);
    }

    /**
     * Update an existing category.
     *
     * @param  array<string, mixed>  $data
     */
    public function updateCategory(Category $category, array $data): Category
    {
        $payload = [];

        if (isset($data['name'])) {
            $payload['name'] = trim($data['name']);
        }

        if (isset($data['slug'])) {
            $newSlug = Str::slug($data['slug']);
            $payload['slug'] = $this->ensureUniqueSlug($newSlug, $category->id);
        }

        if (array_key_exists('description', $data)) {
            $payload['description'] = $data['description'];
        }

        if (isset($data['is_active'])) {
            $payload['is_active'] = (bool) $data['is_active'];
        }

        $category->update($payload);

        return $category->fresh()->loadCount('products');
    }

    /**
     * Toggle category activation.
     */
    public function toggleActivation(Category $category): Category
    {
        $category->is_active = ! $category->is_active;
        $category->save();

        return $category->fresh()->loadCount('products');
    }

    /**
     * Delete a category if it has no associated products.
     *
     *
     * @throws ValidationException
     */
    public function deleteCategory(Category $category): bool
    {
        $productsCount = $category->products()->count();

        if ($productsCount > 0) {
            throw ValidationException::withMessages([
                'category' => [
                    "Cannot delete category '{$category->name}' because it still contains {$productsCount} products. ".
                    'Please reassign or delete the products first, or deactivate the category instead.',
                ],
            ]);
        }

        return (bool) $category->delete();
    }

    /**
     * Ensure slug uniqueness.
     */
    protected function ensureUniqueSlug(string $slug, ?int $ignoreId = null): string
    {
        $baseSlug = $slug;
        $counter = 1;

        while (true) {
            $query = Category::where('slug', $slug);
            if ($ignoreId) {
                $query->where('id', '!=', $ignoreId);
            }

            if (! $query->exists()) {
                return $slug;
            }

            $slug = "{$baseSlug}-{$counter}";
            $counter++;
        }
    }
}
