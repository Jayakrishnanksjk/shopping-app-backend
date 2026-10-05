<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use Laravel\Sanctum\Sanctum;
use App\Models\User;

// Find the token in the database
$token = DB::table('personal_access_tokens')->where('name', 'pearl-xp-master-token')->first();
echo "Token in DB: " . ($token ? "found (id: {$token->id})" : "NOT found") . "\n";

if ($token) {
    $user = User::find($token->tokenable_id);
    echo "Token user: " . ($user ? $user->email : "not found") . "\n";

    // Try to find the token via Sanctum
    $accessToken = Sanctum::$accessTokenModel::find($token->id);
    echo "Sanctum token model: " . ($accessToken ? "found" : "not found") . "\n";

    if ($accessToken) {
        echo "Token name: " . $accessToken->name . "\n";
        echo "Token abilities: " . json_encode($accessToken->abilities) . "\n";
    }
}

// Check sanctum config
echo "\nSanctum config:\n";
echo "guard: " . json_encode(config('sanctum.guard')) . "\n";
echo "expiration: " . json_encode(config('sanctum.expiration')) . "\n";
