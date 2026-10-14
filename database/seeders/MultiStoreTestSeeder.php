<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\Store;
use App\Models\Product;
use App\Models\Category;
use App\Models\Country;
use App\Models\State;
use App\Enums\RoleEnum;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class MultiStoreTestSeeder extends Seeder
{
    public function run()
    {
        // Resolve India + Kerala ids dynamically, fallback to null (columns are nullable).
        $countryId = Country::where('name', 'LIKE', '%India%')->value('id');
        $stateId = State::where('name', 'LIKE', '%Kerala%')->value('id');

        // --- 1. Vendors (idempotent by email) ---
        $foodzoneVendor = $this->ensureVendor(
            'Foodzone Vendor',
            'foodzone.vendor@example.com',
            '91',
            '9847000001'
        );

        $matherVendor = $this->ensureVendor(
            'Mather Jewellery Vendor',
            'mather.jewellery@example.com',
            '91',
            '9847000002'
        );

        // --- 2. Stores (idempotent by slug/store_name). Never touches other stores. ---
        $foodzone = $this->ensureStore($foodzoneVendor, [
            'store_name' => 'Foodzone',
            'description' => 'Foodzone - multi-cuisine restaurant. Biryani, fast food, meals & beverages for testing.',
            'city' => 'Kochi',
            'address' => 'Foodzone, MG Road, Kochi, Kerala',
            'pincode' => '682001',
            'country_id' => $countryId,
            'state_id' => $stateId,
        ]);

        $mather = $this->ensureStore($matherVendor, [
            'store_name' => 'Mather Jewellery',
            'description' => 'Mather Jewellery - gold, diamond and bridal collections for testing.',
            'city' => 'Thrissur',
            'address' => 'Mather Jewellery, Round East, Thrissur, Kerala',
            'pincode' => '680001',
            'country_id' => $countryId,
            'state_id' => $stateId,
        ]);

        // --- 3. Categories (global, idempotent by slug). type=product so products can attach. ---
        foreach ([
            'Foodzone Specials' => 'Test category for Foodzone restaurant items.',
            'Biryani & Meals' => 'Test category for biryani and meals.',
            'Fast Food & Beverages' => 'Test category for fast food and beverages.',
            'Gold Jewellery' => 'Test category for gold jewellery.',
            'Diamond Jewellery' => 'Test category for diamond jewellery.',
            'Bridal Collections' => 'Test category for bridal collections.',
        ] as $name => $description) {
            $this->ensureCategory($name, $description);
        }

        // --- 4. Products (idempotent by SKU). Only creates missing ones, never updates existing. ---
        $foodzoneCatIds = [
            'Biryani & Meals' => Category::where('slug', 'biryani-meals')->value('id'),
            'Fast Food & Beverages' => Category::where('slug', 'fast-food-beverages')->value('id'),
        ];
        $matherCatIds = [
            'Gold Jewellery' => Category::where('slug', 'gold-jewellery')->value('id'),
            'Diamond Jewellery' => Category::where('slug', 'diamond-jewellery')->value('id'),
            'Bridal Collections' => Category::where('slug', 'bridal-collections')->value('id'),
        ];
        $foodProducts = [
            ['name' => 'Chicken Biryani Family Pack', 'short' => 'Test: fragrant chicken biryani family pack.', 'price' => 499, 'sale' => 449, 'qty' => 50, 'sku' => 'FZ-BIRYANI-001', 'unit' => '1 pack', 'cat' => 'Biryani & Meals'],
            ['name' => 'Beef Biryani Single', 'short' => 'Test: beef biryani single serving.', 'price' => 199, 'sale' => 179, 'qty' => 100, 'sku' => 'FZ-BIRYANI-002', 'unit' => '1 plate', 'cat' => 'Biryani & Meals'],
            ['name' => 'Zinger Burger Combo', 'short' => 'Test: crispy zinger burger with fries & coke.', 'price' => 249, 'sale' => 229, 'qty' => 80, 'sku' => 'FZ-FASTFOOD-001', 'unit' => '1 combo', 'cat' => 'Fast Food & Beverages'],
            ['name' => 'Chicken Shawarma Roll', 'short' => 'Test: juicy chicken shawarma roll.', 'price' => 129, 'sale' => 119, 'qty' => 120, 'sku' => 'FZ-FASTFOOD-002', 'unit' => '1 pc', 'cat' => 'Fast Food & Beverages'],
            ['name' => 'Fresh Lime Juice', 'short' => 'Test: fresh lime juice beverage.', 'price' => 79, 'sale' => 69, 'qty' => 200, 'sku' => 'FZ-BEV-001', 'unit' => '350 ml', 'cat' => 'Fast Food & Beverages'],
        ];
        foreach ($foodProducts as $p) {
            $this->ensureProduct($foodzone->id, $p, array_filter([$foodzoneCatIds[$p['cat']] ?? null]));
        }

        $jewelleryProducts = [
            ['name' => '22K Gold Necklace - Test', 'short' => 'Test: 22K gold necklace, 8g approx.', 'price' => 75000, 'sale' => 73500, 'qty' => 5, 'sku' => 'MJ-GOLD-001', 'unit' => '1 pc', 'cat' => 'Gold Jewellery'],
            ['name' => 'Gold Stud Earrings - Test', 'short' => 'Test: gold stud earrings.', 'price' => 18000, 'sale' => 17500, 'qty' => 10, 'sku' => 'MJ-GOLD-002', 'unit' => '1 pair', 'cat' => 'Gold Jewellery'],
            ['name' => 'Diamond Pendant - Test', 'short' => 'Test: diamond pendant in 18K gold.', 'price' => 45000, 'sale' => 43999, 'qty' => 7, 'sku' => 'MJ-DIA-001', 'unit' => '1 pc', 'cat' => 'Diamond Jewellery'],
            ['name' => 'Diamond Ring - Test', 'short' => 'Test: solitaire style diamond ring.', 'price' => 62000, 'sale' => 59999, 'qty' => 4, 'sku' => 'MJ-DIA-002', 'unit' => '1 pc', 'cat' => 'Diamond Jewellery'],
            ['name' => 'Bridal Bangle Set - Test', 'short' => 'Test: bridal bangle set, 4 pcs.', 'price' => 95000, 'sale' => 92999, 'qty' => 3, 'sku' => 'MJ-BRIDAL-001', 'unit' => '1 set', 'cat' => 'Bridal Collections'],
        ];
        foreach ($jewelleryProducts as $p) {
            $this->ensureProduct($mather->id, $p, array_filter([$matherCatIds[$p['cat']] ?? null]));
        }

        $this->command->info('MultiStoreTestSeeder done: Foodzone (id '.$foodzone->id.') + Mather Jewellery (id '.$mather->id.'). Existing products/stores untouched.');
    }

    protected function ensureVendor(string $name, string $email, string $countryCode, string $phone): User
    {
        $vendor = User::where('email', $email)->first();
        if (! $vendor) {
            $vendor = User::create([
                'name' => $name,
                'email' => $email,
                'password' => Hash::make('123456789'),
                'country_code' => $countryCode,
                'phone' => $phone,
                'status' => 1,
            ]);
        }
        if (! $vendor->hasRole(RoleEnum::VENDOR)) {
            $vendor->assignRole(RoleEnum::VENDOR);
        }
        if (! $vendor->vendor_wallet()->exists()) {
            $vendor->vendor_wallet()->create();
        }

        return $vendor;
    }

    protected function ensureStore(User $vendor, array $attrs): Store
    {
        // One store per vendor; also guard by store_name so re-runs are safe.
        $store = Store::where('vendor_id', $vendor->id)->first()
            ?? Store::where('store_name', $attrs['store_name'])->first();

        if (! $store) {
            $store = Store::create(array_merge([
                'facebook' => null,
                'twitter' => null,
                'instagram' => null,
                'youtube' => null,
                'pinterest' => null,
                'store_logo_id' => null,
                'store_cover_id' => null,
                'hide_vendor_email' => 1,
                'hide_vendor_phone' => 1,
                'vendor_id' => $vendor->id,
                'status' => 1,
                'is_approved' => 1,
            ], $attrs));
        }

        return $store;
    }

    protected function ensureCategory(string $name, string $description): Category
    {
        $slug = \Illuminate\Support\Str::slug($name);
        $cat = Category::where('slug', $slug)->first();
        if (! $cat) {
            $cat = Category::create([
                'name' => $name,
                'description' => $description,
                'status' => 1,
                'type' => 'product',
                'commission_rate' => 0,
                'parent_id' => null,
            ]);
        }

        return $cat;
    }

    protected function ensureProduct(int $storeId, array $p, array $categoryIds): Product
    {
        $existing = Product::where('sku', $p['sku'])->first();
        if ($existing) {
            return $existing; // never modify existing rows
        }

        $product = Product::create([
            'name' => $p['name'],
            'short_description' => $p['short'],
            'description' => $p['short'] . ' (Seeded test product.)',
            'type' => 'simple',
            'unit' => $p['unit'],
            'quantity' => $p['qty'],
            'weight' => null,
            'price' => $p['price'],
            'sale_price' => $p['sale'],
            'sku' => $p['sku'],
            'stock_status' => 'in_stock',
            'status' => 1,
            'is_approved' => 1,
            'store_id' => $storeId,
            'is_free_shipping' => 0,
            'is_return' => 1,
            'safe_checkout' => 1,
            'secure_checkout' => 1,
            'social_share' => 1,
            'encourage_order' => 0,
            'encourage_view' => 0,
            'is_random_related_products' => 0,
        ]);

        if (! empty($categoryIds)) {
            $product->categories()->sync($categoryIds);
        }

        return $product;
    }
}
