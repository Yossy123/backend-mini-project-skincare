<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Product;
use Illuminate\Database\Seeder;

class ProductSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $skincare = Category::where('slug', 'skincare')->first();
        $makeup = Category::where('slug', 'makeup')->first();
        $fragrance = Category::where('slug', 'fragrance')->first();
        $bodyCare = Category::where('slug', 'body-care')->first();
        $hairCare = Category::where('slug', 'hair-care')->first();

        $products = [
            // Skincare
            [
                'category_id' => $skincare?->id,
                'name' => 'Lumière Radiance Vitamin C Serum 30ml',
                'slug' => 'lumiere-radiance-vitamin-c-serum-30ml',
                'description' => 'A potent antioxidant serum with 15% pure L-Ascorbic Acid, Ferulic Acid, and Hyaluronic Acid to brighten complexion and fade dark spots.',
                'price' => 389000.00,
                'weight' => 120, // grams
                'stock' => 50,
                'image' => 'products/vitamin-c-serum.jpg',
                'is_active' => true,
            ],
            [
                'category_id' => $skincare?->id,
                'name' => 'Hydra-Luxe Ceramide Barrier Cream 50ml',
                'slug' => 'hydra-luxe-ceramide-barrier-cream-50ml',
                'description' => 'Deeply restorative moisturizer enriched with 5 essential ceramides, squalane, and colloidal oatmeal to repair skin barrier.',
                'price' => 295000.00,
                'weight' => 180,
                'stock' => 75,
                'image' => 'products/barrier-cream.jpg',
                'is_active' => true,
            ],
            [
                'category_id' => $skincare?->id,
                'name' => 'Gentle Botanical Cleansing Balm 100g',
                'slug' => 'gentle-botanical-cleansing-balm-100g',
                'description' => 'Melt away waterproof makeup and impurities without stripping natural moisture. Infused with chamomile and rosehip oil.',
                'price' => 245000.00,
                'weight' => 220,
                'stock' => 60,
                'image' => 'products/cleansing-balm.jpg',
                'is_active' => true,
            ],

            // Makeup
            [
                'category_id' => $makeup?->id,
                'name' => 'Velvet Silk Cushion Foundation SPF 50+',
                'slug' => 'velvet-silk-cushion-foundation-spf-50',
                'description' => 'Semi-matte high coverage cushion foundation with a breathable finish and all-day hydration for a flawless complexion.',
                'price' => 320000.00,
                'weight' => 150,
                'stock' => 40,
                'image' => 'products/cushion-foundation.jpg',
                'is_active' => true,
            ],
            [
                'category_id' => $makeup?->id,
                'name' => 'Aura Petal Soft Matte Lip Tint - French Rose',
                'slug' => 'aura-petal-soft-matte-lip-tint-french-rose',
                'description' => 'Weightless, transfer-proof velvet lip tint that blurs lip lines while delivering rich, romantic pigment.',
                'price' => 165000.00,
                'weight' => 45,
                'stock' => 100,
                'image' => 'products/lip-tint-french-rose.jpg',
                'is_active' => true,
            ],
            [
                'category_id' => $makeup?->id,
                'name' => 'Luminous Glow Liquid Blush - Champagne Peach',
                'slug' => 'luminous-glow-liquid-blush-champagne-peach',
                'description' => 'Seamless liquid blush that melts into skin for a healthy, dewy flush of color with micro-pearl radiance.',
                'price' => 185000.00,
                'weight' => 60,
                'stock' => 85,
                'image' => 'products/liquid-blush.jpg',
                'is_active' => true,
            ],

            // Fragrance
            [
                'category_id' => $fragrance?->id,
                'name' => 'Maison Rose Extrait de Parfum 50ml',
                'slug' => 'maison-rose-extrait-de-parfum-50ml',
                'description' => 'A romantic symphony of Grasse rose, pink pepper, warm vanilla, and velvet musk with extraordinary 12-hour longevity.',
                'price' => 780000.00,
                'weight' => 280,
                'stock' => 30,
                'image' => 'products/maison-rose-parfum.jpg',
                'is_active' => true,
            ],
            [
                'category_id' => $fragrance?->id,
                'name' => 'Solar Citrus & Bergamot Eau de Parfum 50ml',
                'slug' => 'solar-citrus-bergamot-eau-de-parfum-50ml',
                'description' => 'Crisp Italian bergamot paired with white tea, cedarwood, and neroli for a vibrant, uplifting fragrance.',
                'price' => 650000.00,
                'weight' => 280,
                'stock' => 25,
                'image' => 'products/solar-citrus.jpg',
                'is_active' => true,
            ],

            // Body Care
            [
                'category_id' => $bodyCare?->id,
                'name' => 'Nourishing Golden Jojoba Body Oil 150ml',
                'slug' => 'nourishing-golden-jojoba-body-oil-150ml',
                'description' => 'Fast-absorbing dry oil formulated with cold-pressed jojoba, sweet almond, and vitamin E for luminous skin.',
                'price' => 275000.00,
                'weight' => 320,
                'stock' => 50,
                'image' => 'products/body-oil.jpg',
                'is_active' => true,
            ],

            // Hair Care
            [
                'category_id' => $hairCare?->id,
                'name' => 'Intense Peptide Repair Hair Mask 200ml',
                'slug' => 'intense-peptide-repair-hair-mask-200ml',
                'description' => 'Restores damaged hair bonds, seals split ends, and infuses silky softness with botanical keratin.',
                'price' => 290000.00,
                'weight' => 350,
                'stock' => 45,
                'image' => 'products/hair-mask.jpg',
                'is_active' => true,
            ],
        ];

        foreach ($products as $product) {
            if ($product['category_id']) {
                Product::updateOrCreate(['slug' => $product['slug']], $product);
            }
        }
    }
}
