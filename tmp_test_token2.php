<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use Laravel\Sanctum\PersonalAccessToken;

$plainTextToken = '9|npzYmGYbSlOqVSY6trpDsh5B1IyR65GScj2LSKbI62307c0f';

// This is how Sanctum validates tokens
$token = PersonalAccessToken::findToken($plainTextToken);
echo "Sanctum findToken: " . ($token ? "VALID (id: {$token->id})" : "INVALID") . "\n";

if ($token) {
    echo "Token name: " . $token->name . "\n";
    echo "Tokenable: " . ($token->tokenable ? $token->tokenable->email : "none") . "\n";
}
