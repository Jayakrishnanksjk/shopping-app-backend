<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

$user = App\Models\User::find(1);
echo "User: " . $user->email . "\n";
echo "Roles: " . $user->getRoleNames()->implode(', ') . "\n";
echo "Has product.edit permission: " . ($user->hasPermissionTo('product.edit') ? 'yes' : 'no') . "\n";
echo "Can product.edit: " . ($user->can('product.edit') ? 'yes' : 'no') . "\n";

// Check what permissions the admin role has
$adminRole = Spatie\Permission\Models\Role::where('name', 'admin')->first();
if ($adminRole) {
    echo "Admin role permissions: " . $adminRole->permissions->pluck('name')->implode(', ') . "\n";
} else {
    echo "Admin role not found!\n";
}
