<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use Illuminate\Support\Facades\Auth;
use Laravel\Sanctum\PersonalAccessToken;

// Find the token
$tokenRow = DB::table('personal_access_tokens')->where('name', 'pearl-xp-master-token')->first();
echo "Token row: " . ($tokenRow ? "found (id: {$tokenRow->id})" : "NOT found") . "\n";

if ($tokenRow) {
    // Try to find it via Sanctum's model
    $plainTextToken = '9|npzYmGYbSlOqVSY6trpDsh5B1IyR65GScj2LSKbI62307c0f';
    $hashedToken = hash('sha256', $plainTextToken);

    echo "Stored hash: " . substr($tokenRow->token, 0, 20) . "...\n";
    echo "Computed hash: " . substr($hashedToken, 0, 20) . "...\n";
    echo "Hash match: " . (hash_equals($tokenRow->token, $hashedToken) ? 'YES' : 'NO') . "\n";

    // Try to find the token via Sanctum
    $accessToken = PersonalAccessToken::find($tokenRow->id);
    echo "Sanctum token: " . ($accessToken ? "found" : "not found") . "\n";

    if ($accessToken) {
        echo "Token name: " . $accessToken->name . "\n";
        echo "Tokenable: " . ($accessToken->tokenable ? $accessToken->tokenable->email : "none") . "\n";

        // Check if token is valid
        $isValid = $accessToken->check();
        echo "Token check(): " . ($isValid ? 'valid' : 'invalid') . "\n";
    }
}

// Try to authenticate via the api guard
echo "\n--- Testing auth:api guard ---\n";
try {
    $user = Auth::guard('api')->user();
    echo "Auth user: " . ($user ? $user->email : "none") . "\n";
} catch (\Exception $e) {
    echo "Auth error: " . $e->getMessage() . "\n";
}
