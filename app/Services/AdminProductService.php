<?php

namespace App\Services;

use App\Models\Product;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AdminProductService
{
    /**
     * List products with filtering, search, and pagination for back office.
     *
     * @param  array<string, mixed>  $filters
     */
    public function listProducts(array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        $query = Product::query()->with('category:id,name,slug');

        // Search by name, slug, or description
        if (! empty($filters['search'])) {
            $search = trim((string) $filters['search']);
            $query->where(function ($q) use ($search) {
                $q->where('name', 'ilike', "%{$search}%")
                    ->orWhere('slug', 'ilike', "%{$search}%")
                    ->orWhere('description', 'ilike', "%{$search}%");
            });
        }

        // Filter by Category
        if (! empty($filters['category_id'])) {
            $query->where('category_id', (int) $filters['category_id']);
        }

        // Filter by Active Status
        if (isset($filters['is_active']) && $filters['is_active'] !== '' && $filters['is_active'] !== 'all') {
            $isActive = filter_var($filters['is_active'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if ($isActive !== null) {
                $query->where('is_active', $isActive);
            }
        }

        // Filter by Stock Status
        if (! empty($filters['stock_status'])) {
            $stockStatus = strtolower(trim((string) $filters['stock_status']));
            if ($stockStatus === 'in_stock') {
                $query->where('stock', '>', 5);
            } elseif ($stockStatus === 'low_stock') {
                $query->where('stock', '<=', 5)->where('stock', '>', 0);
            } elseif ($stockStatus === 'out_of_stock') {
                $query->where('stock', '<=', 0);
            }
        }

        return $query->orderByDesc('created_at')
            ->paginate(max(1, min(100, $perPage)));
    }

    /**
     * Create a new product.
     *
     * @param  array<string, mixed>  $data
     */
    public function createProduct(array $data, ?UploadedFile $imageFile = null): Product
    {
        // Generate or sanitize slug
        $slug = ! empty($data['slug']) ? Str::slug($data['slug']) : Str::slug($data['name']);
        $slug = $this->ensureUniqueSlug($slug);

        $imagePath = $data['image'] ?? null;
        if ($imageFile) {
            $imagePath = $imageFile->store('products', 'public');
        }

        return Product::create([
            'category_id' => $data['category_id'],
            'name' => trim($data['name']),
            'slug' => $slug,
            'description' => $data['description'] ?? null,
            'price' => (float) $data['price'],
            'weight' => max(1, (int) $data['weight']),
            'stock' => max(0, (int) $data['stock']),
            'image' => $imagePath,
            'is_active' => isset($data['is_active']) ? (bool) $data['is_active'] : true,
        ]);
    }

    /**
     * Update an existing product master record.
     * Historical order items remain untouched and immutable.
     *
     * @param  array<string, mixed>  $data
     */
    public function updateProduct(Product $product, array $data, ?UploadedFile $imageFile = null): Product
    {
        $payload = [];

        if (isset($data['name'])) {
            $payload['name'] = trim($data['name']);
        }

        if (isset($data['slug'])) {
            $newSlug = Str::slug($data['slug']);
            $payload['slug'] = $this->ensureUniqueSlug($newSlug, $product->id);
        }

        if (isset($data['category_id'])) {
            $payload['category_id'] = $data['category_id'];
        }

        if (array_key_exists('description', $data)) {
            $payload['description'] = $data['description'];
        }

        if (isset($data['price'])) {
            $payload['price'] = (float) $data['price'];
        }

        if (isset($data['weight'])) {
            $payload['weight'] = max(1, (int) $data['weight']);
        }

        if (isset($data['stock'])) {
            $payload['stock'] = max(0, (int) $data['stock']);
        }

        if (isset($data['is_active'])) {
            $payload['is_active'] = (bool) $data['is_active'];
        }

        if ($imageFile) {
            // Delete previous local storage image if existed
            if ($product->image && Storage::disk('public')->exists($product->image)) {
                Storage::disk('public')->delete($product->image);
            }
            $payload['image'] = $imageFile->store('products', 'public');
        } elseif (isset($data['image'])) {
            $payload['image'] = $data['image'];
        }

        $product->update($payload);

        return $product->fresh('category');
    }

    /**
     * Toggle product activation status (soft activation/deactivation).
     */
    public function toggleActivation(Product $product): Product
    {
        $product->is_active = ! $product->is_active;
        $product->save();

        return $product->fresh('category');
    }

    /**
     * Controlled inventory stock adjustment with row locking and negative prevention.
     *
     * @param  string  $type  'set'|'increment'|'decrement'
     *
     * @throws ValidationException
     */
    public function adjustStock(int $productId, string $type, int $amount, ?string $reason = null): Product
    {
        return DB::transaction(function () use ($productId, $type, $amount) {
            /** @var Product $product */
            $product = Product::where('id', $productId)->lockForUpdate()->firstOrFail();

            $newStock = $product->stock;

            if ($type === 'set') {
                if ($amount < 0) {
                    throw ValidationException::withMessages([
                        'amount' => ['Target stock count cannot be negative.'],
                    ]);
                }
                $newStock = $amount;
            } elseif ($type === 'increment') {
                if ($amount <= 0) {
                    throw ValidationException::withMessages([
                        'amount' => ['Increment quantity must be at least 1.'],
                    ]);
                }
                $newStock = $product->stock + $amount;
            } elseif ($type === 'decrement') {
                if ($amount <= 0) {
                    throw ValidationException::withMessages([
                        'amount' => ['Decrement quantity must be at least 1.'],
                    ]);
                }
                if ($product->stock - $amount < 0) {
                    throw ValidationException::withMessages([
                        'amount' => ["Cannot reduce {$amount} units. Current available stock is only {$product->stock}."],
                    ]);
                }
                $newStock = $product->stock - $amount;
            } else {
                throw ValidationException::withMessages([
                    'type' => ['Invalid adjustment type. Must be set, increment, or decrement.'],
                ]);
            }

            $product->stock = $newStock;
            $product->save();

            return $product->fresh('category');
        });
    }

    /**
     * Ensure slug uniqueness.
     */
    protected function ensureUniqueSlug(string $slug, ?int $ignoreId = null): string
    {
        $baseSlug = $slug;
        $counter = 1;

        while (true) {
            $query = Product::where('slug', $slug);
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
