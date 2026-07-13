<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Product;
use Illuminate\Support\Str;

class GenerateProductSlugsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run()
    {
        $this->command->info('Starting to generate slugs and hash_ids for products...');

        $products = Product::all();
        $total = $products->count();
        $updated = 0;

        if ($total === 0) {
            $this->command->warn('No products found in the database.');
            return;
        }

        $this->command->info("Found {$total} products to process.");

        foreach ($products as $product) {
            $changed = false;

            // Generate hash_id if missing
            if (empty($product->hash_id)) {
                $hash = $this->generateUniqueHashId();
                $product->hash_id = $hash;
                $changed = true;
                $this->command->line("Generated hash_id for product ID {$product->product_id}: {$hash}");
            }

            // Generate slug if missing
            if (empty($product->slug)) {
                $slug = $this->generateUniqueSlug($product->title, $product->product_id);
                $product->slug = $slug;
                $changed = true;
                $this->command->line("Generated slug for product ID {$product->product_id}: {$slug}");
            }

            if ($changed) {
                $product->save();
                $updated++;
            }
        }

        $this->command->info("Successfully updated {$updated} out of {$total} products.");
        $this->command->info('All slugs and hash_ids have been generated!');
    }

    /**
     * Generate a unique hash ID (20 character random string)
     */
    private function generateUniqueHashId(): string
    {
        do {
            $hash = Str::random(20);
        } while (Product::where('hash_id', $hash)->exists());

        return $hash;
    }

    /**
     * Generate a unique slug from title
     */
    private function generateUniqueSlug(string $title, int $productId): string
    {
        // Convert to URL-friendly slug
        $slug = Str::slug($title);

        // Fallback if slug is empty (e.g., title is only special chars)
        if (empty($slug)) {
            $slug = 'product-' . Str::random(6);
        }

        // Check if slug exists, excluding current product
        $existingSlugs = Product::where('slug', 'LIKE', "{$slug}%")
            ->where('product_id', '!=', $productId)
            ->pluck('slug')
            ->toArray();

        // If no duplicates, return the slug
        if (!in_array($slug, $existingSlugs)) {
            return $slug;
        }

        // Add number suffix for duplicates
        $counter = 1;
        $newSlug = $slug . '-' . $counter;

        while (in_array($newSlug, $existingSlugs)) {
            $counter++;
            $newSlug = $slug . '-' . $counter;
        }

        return $newSlug;
    }
}
