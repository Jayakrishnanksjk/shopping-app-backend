<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Product;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

try {
    DB::statement('PRAGMA foreign_keys=OFF;');

    $product1 = Product::firstOrCreate(['barcode' => '8901234567890'], [
        'name' => 'Test Product 1',
        'slug' => 'test-product-1-'.time(),
        'type' => 'simple',
        'price' => 100,
        'cost' => 50,
        'quantity' => 10,
        'sku' => 'TEST-1-'.time(),
        'status' => 1,
    ]);
    
    $product2 = Product::firstOrCreate(['barcode' => '8901234567891'], [
        'name' => 'Test Product 2',
        'slug' => 'test-product-2-'.time(),
        'type' => 'simple',
        'price' => 200,
        'cost' => 100,
        'quantity' => 20,
        'sku' => 'TEST-2-'.time(),
        'status' => 1,
    ]);
} catch (\Exception $e) {
    echo "Product creation failed: " . $e->getMessage() . "\n";
}

Artisan::call('master-token:reset');
$output = Artisan::output();
preg_match('/([0-9]+\|[a-zA-Z0-9]+)/', $output, $matches);
$token = $matches[1] ?? null;

$payload = [
    'items' => [
        [
            'barcode' => '8901234567890',
            'mrp' => 150.00,
            'price' => 120.00,
            'stock' => 50
        ],
        [
            'barcode' => '8901234567891',
            'price' => 210.00,
            'stock' => 15
        ]
    ]
];

echo "Sending ALL VALID payload to API...\n";
$request = Request::create('/api/pearl-xp/product-updates', 'POST', [], [], [], [
    'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
    'CONTENT_TYPE' => 'application/json',
], json_encode($payload));

$response = app()->handle($request);
echo "Response Body:\n" . json_encode(json_decode($response->getContent()), JSON_PRETTY_PRINT) . "\n";
