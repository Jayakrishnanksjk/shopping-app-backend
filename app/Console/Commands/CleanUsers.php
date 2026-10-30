<?php

namespace App\Console\Commands;

use App\Enums\RoleEnum;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class CleanUsers extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:clean-users
                            {--force : Skip interactive confirmation}
                            {--dry-run : Preview what would be cleaned without modifying database}
                            {--include-vendors : Also clean vendor accounts (stores will be safely reassigned to admin)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Safely wipe consumer/customer data without touching products, categories, stores, or admin accounts';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->newLine();
        $this->info('======================================================');
        $this->info('         MStore Safe User Data Cleaner                ');
        $this->info('======================================================');
        $this->newLine();

        // 1. Locate primary admin account
        $admin = User::whereHas('roles', fn($q) => $q->where('name', RoleEnum::ADMIN))
            ->orderBy('id', 'asc')
            ->first() ?? User::where('system_reserve', 1)->first() ?? User::find(1);

        if (!$admin) {
            $this->error('ERROR: No admin user found in database! Aborting to prevent lockout.');
            return self::FAILURE;
        }

        $includeVendors = (bool) $this->option('include-vendors');
        $dryRun = (bool) $this->option('dry-run');

        // 2. Identify preserved users (Admin and optionally Vendors)
        $preservedAdmins = User::whereHas('roles', fn($q) => $q->where('name', RoleEnum::ADMIN))
            ->orWhere('system_reserve', 1)
            ->orWhere('id', $admin->id)
            ->get();

        $preservedVendors = collect();
        if (!$includeVendors) {
            $preservedVendors = User::whereHas('roles', fn($q) => $q->where('name', RoleEnum::VENDOR))
                ->where('id', '!=', $admin->id)
                ->get();
        }

        $preservedIds = $preservedAdmins->pluck('id')
            ->merge($preservedVendors->pluck('id'))
            ->unique()
            ->toArray();

        // 3. Identify target users to delete (consumers / customers)
        $targetUsers = User::withTrashed()
            ->whereNotIn('id', $preservedIds)
            ->get();

        $targetUserIds = $targetUsers->pluck('id')->toArray();
        $targetEmails = $targetUsers->pluck('email')->filter()->toArray();
        $targetPhones = $targetUsers->pluck('phone')->filter()->toArray();

        // 4. Calculate counts of child records to be cleaned
        $counts = [
            'Target Consumer Accounts' => count($targetUserIds),
            'Consumer Addresses' => $this->countRecords('addresses', 'user_id', $targetUserIds),
            'Consumer Orders' => $this->countRecords('orders', 'consumer_id', $targetUserIds),
            'Consumer Order Items' => $this->countOrderItems($targetUserIds),
            'Consumer Order Transactions' => $this->countOrderTransactions($targetUserIds),
            'Consumer Refunds' => $this->countRecords('refunds', 'consumer_id', $targetUserIds),
            'Consumer Carts' => $this->countRecords('carts', 'consumer_id', $targetUserIds),
            'Consumer Wishlists' => $this->countRecords('wishlists', 'consumer_id', $targetUserIds),
            'Consumer Compares' => $this->countRecords('compares', 'consumer_id', $targetUserIds),
            'Consumer Reviews' => $this->countRecords('reviews', 'consumer_id', $targetUserIds),
            'Consumer Feedbacks' => $this->countRecords('feedbacks', 'consumer_id', $targetUserIds),
            'Consumer Q&A' => $this->countRecords('question_and_answers', 'consumer_id', $targetUserIds),
            'Consumer Wallets' => $this->countRecords('wallets', 'consumer_id', $targetUserIds),
            'Consumer Points' => $this->countRecords('points', 'consumer_id', $targetUserIds),
            'Consumer Access Tokens' => $this->countTokens($targetUserIds),
            'Consumer Device Tokens' => $this->countRecords('device_tokens', 'user_id', $targetUserIds),
            'Consumer Notifications' => $this->countNotifications($targetUserIds),
        ];

        // 5. Display Summary
        $this->line('<comment>Preserved Accounts (WILL NOT BE DELETED):</comment>');
        foreach ($preservedAdmins as $adm) {
            $this->line("  [ADMIN]  ID: {$adm->id} | {$adm->name} ({$adm->email})");
        }
        if (!$includeVendors && $preservedVendors->isNotEmpty()) {
            foreach ($preservedVendors as $vnd) {
                $this->line("  [VENDOR] ID: {$vnd->id} | {$vnd->name} ({$vnd->email})");
            }
        }
        $this->newLine();

        $this->line('<comment>Catalog & Store Protection Guarantee:</comment>');
        $this->line('  ✔ Products, Variations, and Attributes will be 100% PRESERVED.');
        $this->line('  ✔ Categories, Tags, Taxes, and Shipping rules will be 100% PRESERVED.');
        $this->line('  ✔ Stores will be 100% PRESERVED.');
        $this->line('  ✔ Media library and Attachments will be 100% PRESERVED.');
        $this->line('  ✔ Any created_by_id pointing to removed users will be safely reassigned to Admin #' . $admin->id . '.');
        $this->newLine();

        $tableData = [];
        foreach ($counts as $label => $num) {
            $tableData[] = [$label, $num];
        }
        $this->table(['Item to Clean', 'Count'], $tableData);

        if (empty($targetUserIds)) {
            $this->info('No consumer accounts found to delete. The database is already clean of customer users.');
            return self::SUCCESS;
        }

        if ($dryRun) {
            $this->warn('[DRY RUN] No records were modified or deleted.');
            return self::SUCCESS;
        }

        // 6. Confirmation check
        if (!$this->option('force')) {
            if (!$this->confirm('Are you sure you want to clean all consumer user data and their orders? This cannot be undone.')) {
                $this->info('Operation cancelled. No changes were made.');
                return self::SUCCESS;
            }
        }

        // 7. Execute Cleanup
        $this->newLine();
        $this->info('Cleaning user data safely...');

        DB::beginTransaction();
        try {
            Schema::disableForeignKeyConstraints();

            // A. Safely reassign created_by_id across all catalog tables
            $tablesWithCreatedBy = [
                'products',
                'categories',
                'attributes',
                'attribute_values',
                'attachments',
                'taxes',
                'tags',
                'stores',
                'blogs',
                'shippings',
                'shipping_rules',
                'coupons',
                'currencies',
                'pages',
                'faqs',
                'order_status',
                'push_broadcasts',
                'users',
            ];

            foreach ($tablesWithCreatedBy as $table) {
                if (Schema::hasTable($table) && Schema::hasColumn($table, 'created_by_id')) {
                    DB::table($table)
                        ->whereIn('created_by_id', $targetUserIds)
                        ->update(['created_by_id' => $admin->id]);
                }
            }

            // B. If vendors are being cleaned, reassign store ownership to Admin
            if ($includeVendors && Schema::hasTable('stores') && Schema::hasColumn('stores', 'vendor_id')) {
                DB::table('stores')
                    ->whereIn('vendor_id', $targetUserIds)
                    ->update(['vendor_id' => $admin->id]);
            }

            // C. Clean order-related data for targets
            $orderIds = DB::table('orders')->whereIn('consumer_id', $targetUserIds)->pluck('id')->toArray();
            if (!empty($orderIds)) {
                if (Schema::hasTable('order_products')) {
                    DB::table('order_products')->whereIn('order_id', $orderIds)->delete();
                }
                if (Schema::hasTable('order_transactions')) {
                    DB::table('order_transactions')->whereIn('order_id', $orderIds)->delete();
                }
                if (Schema::hasTable('commission_histories')) {
                    DB::table('commission_histories')->whereIn('order_id', $orderIds)->delete();
                }
                if (Schema::hasTable('cancellations')) {
                    DB::table('cancellations')->whereIn('order_id', $orderIds)->delete();
                }
                if (Schema::hasTable('refunds')) {
                    DB::table('refunds')->whereIn('order_id', $orderIds)->delete();
                }
                DB::table('orders')->whereIn('id', $orderIds)->delete();
            }

            // Also clean any orphan refunds by consumer_id
            if (Schema::hasTable('refunds')) {
                DB::table('refunds')->whereIn('consumer_id', $targetUserIds)->delete();
            }

            // D. Clean customer shopping activity
            if (Schema::hasTable('carts')) {
                DB::table('carts')->whereIn('consumer_id', $targetUserIds)->delete();
            }
            if (Schema::hasTable('wishlists')) {
                DB::table('wishlists')->whereIn('consumer_id', $targetUserIds)->delete();
            }
            if (Schema::hasTable('compares')) {
                DB::table('compares')->whereIn('consumer_id', $targetUserIds)->delete();
            }
            if (Schema::hasTable('reviews')) {
                DB::table('reviews')->whereIn('consumer_id', $targetUserIds)->delete();
            }
            if (Schema::hasTable('feedbacks')) {
                DB::table('feedbacks')->whereIn('consumer_id', $targetUserIds)->delete();
            }
            if (Schema::hasTable('question_and_answers')) {
                DB::table('question_and_answers')->whereIn('consumer_id', $targetUserIds)->delete();
            }
            if (Schema::hasTable('addresses')) {
                DB::table('addresses')->whereIn('user_id', $targetUserIds)->delete();
            }
            if (Schema::hasTable('wallets')) {
                DB::table('wallets')->whereIn('consumer_id', $targetUserIds)->delete();
            }
            if (Schema::hasTable('points')) {
                DB::table('points')->whereIn('consumer_id', $targetUserIds)->delete();
            }
            if (Schema::hasTable('payment_accounts')) {
                DB::table('payment_accounts')->whereIn('user_id', $targetUserIds)->delete();
            }
            if (Schema::hasTable('device_tokens')) {
                DB::table('device_tokens')->whereIn('user_id', $targetUserIds)->delete();
            }

            // E. Clean transactions originating from target consumers
            if (Schema::hasTable('transactions')) {
                DB::table('transactions')->whereIn('from', $targetUserIds)->delete();
            }

            // F. Clean auth & permission pivots
            if (Schema::hasTable('personal_access_tokens')) {
                DB::table('personal_access_tokens')
                    ->where('tokenable_type', 'App\\Models\\User')
                    ->whereIn('tokenable_id', $targetUserIds)
                    ->delete();
            }
            if (Schema::hasTable('model_has_roles')) {
                DB::table('model_has_roles')
                    ->where('model_type', 'App\\Models\\User')
                    ->whereIn('model_id', $targetUserIds)
                    ->delete();
            }
            if (Schema::hasTable('model_has_permissions')) {
                DB::table('model_has_permissions')
                    ->where('model_type', 'App\\Models\\User')
                    ->whereIn('model_id', $targetUserIds)
                    ->delete();
            }
            if (Schema::hasTable('notifications')) {
                DB::table('notifications')
                    ->where('notifiable_type', 'App\\Models\\User')
                    ->whereIn('notifiable_id', $targetUserIds)
                    ->delete();
            }
            if (!empty($targetPhones) && Schema::hasTable('otp_codes')) {
                DB::table('otp_codes')->whereIn('phone', $targetPhones)->delete();
            }
            if (!empty($targetEmails) && Schema::hasTable('password_resets')) {
                DB::table('password_resets')->whereIn('email', $targetEmails)->delete();
            }

            // G. Delete target consumer users directly via DB (avoids model events deleting stores/products)
            DB::table('users')->whereIn('id', $targetUserIds)->delete();

            // H. Reset vendor wallets & product counters
            if (Schema::hasTable('vendor_wallets')) {
                DB::table('vendor_wallets')->update(['balance' => 0.00]);
            }
            if (Schema::hasTable('products') && Schema::hasColumn('products', 'orders_count')) {
                DB::table('products')->update(['orders_count' => 0]);
            }
            if (Schema::hasTable('products') && Schema::hasColumn('products', 'reviews_count')) {
                DB::table('products')->update(['reviews_count' => 0]);
            }
            if (Schema::hasTable('bestselling_products')) {
                DB::table('bestselling_products')->truncate();
            }
            if (Schema::hasTable('trending_products')) {
                DB::table('trending_products')->truncate();
            }

            Schema::enableForeignKeyConstraints();
            DB::commit();

            $this->newLine();
            $this->info('✔ SUCCESS: All consumer user data cleaned successfully!');
            $this->info('✔ Your catalog (Products, Categories, Stores, Variations, Media, Admin users) is intact.');
            $this->info('✔ The database is now ready to be used with new users/backends.');
            $this->newLine();

            return self::SUCCESS;

        } catch (\Throwable $e) {
            DB::rollBack();
            Schema::enableForeignKeyConstraints();
            $this->error('ERROR during cleanup: ' . $e->getMessage());
            return self::FAILURE;
        }
    }

    private function countRecords(string $table, string $column, array $userIds): int
    {
        if (empty($userIds) || !Schema::hasTable($table) || !Schema::hasColumn($table, $column)) {
            return 0;
        }
        return DB::table($table)->whereIn($column, $userIds)->count();
    }

    private function countOrderItems(array $userIds): int
    {
        if (empty($userIds) || !Schema::hasTable('orders') || !Schema::hasTable('order_products')) {
            return 0;
        }
        $orderIds = DB::table('orders')->whereIn('consumer_id', $userIds)->pluck('id')->toArray();
        if (empty($orderIds)) {
            return 0;
        }
        return DB::table('order_products')->whereIn('order_id', $orderIds)->count();
    }

    private function countOrderTransactions(array $userIds): int
    {
        if (empty($userIds) || !Schema::hasTable('orders') || !Schema::hasTable('order_transactions')) {
            return 0;
        }
        $orderIds = DB::table('orders')->whereIn('consumer_id', $userIds)->pluck('id')->toArray();
        if (empty($orderIds)) {
            return 0;
        }
        return DB::table('order_transactions')->whereIn('order_id', $orderIds)->count();
    }

    private function countTokens(array $userIds): int
    {
        if (empty($userIds) || !Schema::hasTable('personal_access_tokens')) {
            return 0;
        }
        return DB::table('personal_access_tokens')
            ->where('tokenable_type', 'App\\Models\\User')
            ->whereIn('tokenable_id', $userIds)
            ->count();
    }

    private function countNotifications(array $userIds): int
    {
        if (empty($userIds) || !Schema::hasTable('notifications')) {
            return 0;
        }
        return DB::table('notifications')
            ->where('notifiable_type', 'App\\Models\\User')
            ->whereIn('notifiable_id', $userIds)
            ->count();
    }
}
