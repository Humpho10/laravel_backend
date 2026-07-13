<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Category;
use App\Models\SubCategory;

class CategorySeeder extends Seeder
{
    public function run(): void
    {
        $data = [
            'Electronics' => [
                'Laptops & Computers',
                'Motherboards',
                'RAM',
                'Processors',
                'Storage Drives',
                'Laptop Screens',
                'Power Supplies',
                'Cooling Fans',
                'Circuit Boards',
                'Batteries',
            ],
            'Mobile Devices' => [
                'Phone Screens',
                'Phone Batteries',
                'Phone Chargers',
                'Phone Boards',
                'SIM Card Trays',
            ],
            'Accessories' => [
                'Cables & Adapters',
                'Keyboards',
                'Mice',
                'Chargers',
                'Headphones & Speakers',
                'Webcams',
            ],
            'Networking' => [
                'Routers',
                'Network Cards',
                'Switches',
                'Modems',
            ],
            'Appliances' => [
                'Printers',
                'Scanners',
                'Projectors',
                'UPS & Inverters',
            ],
            'Other' => [
                'Miscellaneous',
            ],
        ];

        foreach ($data as $categoryName => $subcategories) {
            $category = Category::firstOrCreate(
                ['name' => $categoryName]
            );

            foreach ($subcategories as $subName) {
                    Subcategory::firstOrCreate([
                     'category_id'       => $category->category_id,
                     'sub_category_name' => $subName,
                     ]);
                     }
        }
    }
}
