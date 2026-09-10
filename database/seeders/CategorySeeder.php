<?php

namespace Database\Seeders;

use App\Models\Category;
use Illuminate\Database\Seeder;

class CategorySeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $categories = [
            [
                'name' => 'Skincare',
                'slug' => 'skincare',
                'description' => 'Targeted treatments, hydrating serums, gentle cleansers, and barrier-repair moisturizers.',
                'is_active' => true,
            ],
            [
                'name' => 'Makeup',
                'slug' => 'makeup',
                'description' => 'Luminous foundations, velvet lip tints, radiant blushers, and defining eye essentials.',
                'is_active' => true,
            ],
            [
                'name' => 'Body Care',
                'slug' => 'body-care',
                'description' => 'Botanical body oils, smoothing scrubs, and intensely nourishing body butters.',
                'is_active' => true,
            ],
            [
                'name' => 'Hair Care',
                'slug' => 'hair-care',
                'description' => 'Restorative hair masks, peptide scalp serums, and shine-enhancing elixirs.',
                'is_active' => true,
            ],
        ];

        foreach ($categories as $category) {
            Category::updateOrCreate(['slug' => $category['slug']], $category);
        }
    }
}
