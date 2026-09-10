<?php

namespace App\Filters;

use App\Models\Product;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

class ProductQueryFilter
{
    /**
     * Apply all catalog query filters to the Product query builder.
     *
     * @param  Builder<Product>  $query
     * @return Builder<Product>
     */
    public function apply(Builder $query, Request $request, ?string $categorySlug = null): Builder
    {
        // Enforce active products only
        $query->where('is_active', true);

        // Always eager load category
        $query->with('category');

        // Category filter (either from route param or query param)
        $category = $categorySlug ?? $request->query('category');
        if (! empty($category)) {
            $query->whereHas('category', function (Builder $q) use ($category) {
                if (is_numeric($category)) {
                    $q->where('id', (int) $category);
                } else {
                    $q->where('slug', $category);
                }
            });
        }

        // Search query filter (matches product name or description)
        if ($search = $request->query('search')) {
            $search = mb_strtolower(trim($search));
            $query->where(function (Builder $q) use ($search) {
                $q->whereRaw('LOWER(name) LIKE ?', ["%{$search}%"])
                    ->orWhereRaw('LOWER(description) LIKE ?', ["%{$search}%"]);
            });
        }

        // Sorting
        $sort = $request->query('sort', 'latest');
        match ($sort) {
            'price_asc' => $query->orderBy('price', 'asc')->orderBy('id', 'asc'),
            'price_desc' => $query->orderBy('price', 'desc')->orderBy('id', 'desc'),
            'name_asc' => $query->orderBy('name', 'asc'),
            'name_desc' => $query->orderBy('name', 'desc'),
            default => $query->orderBy('created_at', 'desc')->orderBy('id', 'desc'),
        };

        return $query;
    }
}
