<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\Product;
use App\Models\User;
use App\Models\SubCategory;

class ProductSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // 1. Fetch your seeded user
        $seller = User::where('email', 'seller@ewaste.com')->first();
        
        // 2. Fetch the subcategory using the exact column name 'sub_category_name'
        $subcategory = SubCategory::where('sub_category_name', 'Laptops')->first();

        // 3. Fallback check: If seeders ran weirdly, find ANY subcategory as a backup
        if (!$subcategory) {
            $subcategory = SubCategory::first();
        }
        if (!$seller) {
            $seller = User::first();
        }

        // 4. Create the product
        Product::create([
            'seller_id'       => $seller->id,
            'category_id'     => $subcategory->category_id,
            'subcategory_id'  => $subcategory->subcategory_id,
            'title'           => 'Broken Dell Inspiron 15',
            'description'     => 'Laptop does not boot up. Motherboard damage. Good for scrap components.',
            'condition'       => 'Scrap / Broken',
            'price'           => 45000.00,
            'specification'   => '8GB RAM, No HDD, Core i5 Processor',
            'status'          => 'active',
        ]);
    }
}
