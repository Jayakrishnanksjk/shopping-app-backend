<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use App\Models\Tax;
use App\Models\Product;

class ConsolidateTaxes extends Command
{
    protected $signature = 'app:consolidate-taxes {--dry-run : Simulate consolidation without making database changes} {--force : Execute changes}';
    protected $description = 'Consolidates duplicate tax records by rate and re-points all product associations safely';

    public function handle()
    {
        $isDryRun = $this->option('dry-run') || !$this->option('force');

        $this->info("==================================================");
        $this->info("        TAX DEDUPLICATION & CONSOLIDATION         ");
        $this->info("==================================================");
        if ($isDryRun) {
            $this->warn("MODE: DRY-RUN (No database records will be modified)");
            $this->warn("Pass '--force' to execute the actual migration.");
        } else {
            $this->alert("MODE: LIVE EXECUTION (Database will be updated)");
        }

        // Fetch all taxes grouped by rounded rate
        $taxes = DB::table('taxes')->get();
        if ($taxes->isEmpty()) {
            $this->info("No tax records found in database.");
            return 0;
        }

        $grouped = [];
        foreach ($taxes as $tax) {
            $rateKey = number_format(floatval($tax->rate), 2, '.', '');
            $grouped[$rateKey][] = $tax;
        }

        $totalDuplicateRows = 0;
        $totalProductsToUpdate = 0;
        $consolidationPlan = [];

        foreach ($grouped as $rate => $taxList) {
            // Sort by ID ascending so the earliest, canonical tax is preserved
            usort($taxList, function ($a, $b) {
                return $a->id - $b->id;
            });

            $canonical = $taxList[0];
            $duplicates = array_slice($taxList, 1);
            $duplicateIds = array_map(function ($t) { return $t->id; }, $duplicates);

            $productCount = empty($duplicateIds) ? 0 : DB::table('products')->whereIn('tax_id', $duplicateIds)->count();

            $consolidationPlan[] = [
                'rate' => $rate . '%',
                'canonical_id' => $canonical->id,
                'canonical_name' => $canonical->name,
                'duplicate_count' => count($duplicates),
                'duplicate_ids' => $duplicateIds,
                'products_affected' => $productCount,
            ];

            $totalDuplicateRows += count($duplicates);
            $totalProductsToUpdate += $productCount;
        }

        // Display Summary Table
        $this->newLine();
        $this->table(
            ['Tax Rate', 'Keep ID (Canonical)', 'Name', 'Duplicates to Delete', 'Products to Re-link'],
            array_map(function ($plan) {
                return [
                    $plan['rate'],
                    $plan['canonical_id'],
                    $plan['canonical_name'],
                    $plan['duplicate_count'],
                    $plan['products_affected'],
                ];
            }, $consolidationPlan)
        );

        $this->info("Summary: Total duplicates to remove: {$totalDuplicateRows} | Total products to re-point: {$totalProductsToUpdate}");

        if ($totalDuplicateRows === 0) {
            $this->info("Tax table is already clean! No duplicate taxes exist.");
            return 0;
        }

        if ($isDryRun) {
            $this->newLine();
            $this->warn("Dry run complete. Run with 'php artisan app:consolidate-taxes --force' to apply changes.");
            return 0;
        }

        // Execute inside database transaction
        DB::beginTransaction();
        try {
            foreach ($consolidationPlan as $plan) {
                if (!empty($plan['duplicate_ids'])) {
                    $canonicalId = $plan['canonical_id'];
                    $dupeIds = $plan['duplicate_ids'];

                    // 1. Re-link products
                    $updated = DB::table('products')
                        ->whereIn('tax_id', $dupeIds)
                        ->update(['tax_id' => $canonicalId]);

                    // 2. Delete duplicate taxes
                    $deleted = DB::table('taxes')
                        ->whereIn('id', $dupeIds)
                        ->delete();

                    $this->line("Rate {$plan['rate']}: Re-linked {$updated} products to ID {$canonicalId}, deleted {$deleted} duplicate taxes.");
                }
            }

            DB::commit();
            $this->newLine();
            $this->info("SUCCESS: All tax duplicates consolidated successfully!");
            $this->info("Products now cleanly reference canonical tax IDs.");
            return 0;
        } catch (\Exception $e) {
            DB::rollback();
            $this->error("ERROR during consolidation: " . $e->getMessage());
            return 1;
        }
    }
}
