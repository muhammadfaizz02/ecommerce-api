<?php

namespace Database\Seeders;

use App\Models\Product;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class ProductSeeder extends Seeder
{
    public function run(): void
    {
        $products = [
            [
                'name' => 'Laptop ASUS ROG',
                'description' => 'Gaming laptop dengan spesifikasi tinggi',
                'price' => 15000000,
                'stock' => 10
            ],
            [
                'name' => 'iPhone 15 Pro',
                'description' => 'Smartphone flagship dari Apple',
                'price' => 20000000,
                'stock' => 15
            ],
            [
                'name' => 'Samsung Galaxy S24',
                'description' => 'Smartphone Android terbaru dari Samsung',
                'price' => 12000000,
                'stock' => 20
            ],
            [
                'name' => 'MacBook Air M2',
                'description' => 'Laptop tipis dan ringan dari Apple',
                'price' => 18000000,
                'stock' => 8
            ],
            [
                'name' => 'Logitech MX Master 3',
                'description' => 'Mouse wireless premium untuk produktivitas',
                'price' => 1200000,
                'stock' => 30
            ],
        ];

        foreach ($products as $product) {
            Product::create($product);
        }
    }
}
