<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

// Create the product.edit permission if it doesn't exist
$permission = Permission::firstOrCreate(['name' => 'product.edit']);
echo "Permission 'product.edit' created/found: {$permission->id}\n";

// Make sure the admin role exists and has the permission
$adminRole = Role::firstOrCreate(['name' => 'admin']);
$adminRole->givePermissionTo($permission);
echo "Admin role now has product.edit permission\n";

// Verify
$user = App\Models\User::find(1);
echo "User can product.edit: " . ($user->can('product.edit') ? 'yes' : 'no') . "\n";
