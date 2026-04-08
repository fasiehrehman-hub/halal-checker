<?php

namespace Database\Seeders;

use App\Models\Product;
use Illuminate\Database\Seeder;

class ProductSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $products = [
            [
                'barcode' => '7622210449283',
                'name' => 'Cadbury Dairy Milk',
                'category' => 'chocolate',
                'status' => 'halal',
                'decision_source' => 'internal_review',
                'notes' => 'Verified from internal product list.',
            ],
            [
                'barcode' => '7622202240386',
                'name' => 'Cadbury Bournville',
                'category' => 'chocolate',
                'status' => 'mashbooh',
                'decision_source' => 'internal_review',
                'notes' => 'Requires further verification.',
            ],
            [
                'barcode' => '5000159484695',
                'name' => 'KitKat 4 Finger',
                'category' => 'chocolate',
                'status' => 'halal',
                'decision_source' => 'internal_review',
                'notes' => null,
            ],
            [
                'barcode' => '1234567890123',
                'name' => 'Example Candy Gelatin Mix',
                'category' => 'candy',
                'status' => 'haram',
                'decision_source' => 'internal_review',
                'notes' => 'Contains non-halal gelatin.',
            ],
            [
                'barcode' => '9876543210001',
                'name' => 'Chocolate Wafer Deluxe',
                'category' => 'chocolate',
                'status' => 'pending',
                'decision_source' => 'pending_review',
                'notes' => 'Awaiting review.',
            ],
        ];

        foreach ($products as $product) {
            Product::updateOrCreate(
                ['barcode' => $product['barcode']],
                $product
            );
        }
    }
}