<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('reward_settings', function (Blueprint $table) {
            $table->id();
            $table->boolean('rewards_enabled')->default(true);
            $table->decimal('purchase_threshold', 10, 2)->default(100.00); // e.g. 100
            $table->bigInteger('points_per_threshold')->default(1);        // e.g. 1 point
            $table->decimal('point_conversion_value', 10, 4)->default(0.0085); // Confidential internal rate: ₹0.0085
            $table->timestamps();
        });

        // Insert default initial row
        DB::table('reward_settings')->insert([
            'id' => 1,
            'rewards_enabled' => true,
            'purchase_threshold' => 100.00,
            'points_per_threshold' => 1,
            'point_conversion_value' => 0.0085,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('reward_settings');
    }
};
