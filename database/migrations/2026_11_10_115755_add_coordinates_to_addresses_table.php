<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('addresses', function (Blueprint $table) {
            $table->string('latitude')->nullable()->after('pincode');
            $table->string('longitude')->nullable()->after('latitude');
            $table->string('screen_geo_coordinates')->nullable()->after('longitude');
            $table->text('map_url')->nullable()->after('screen_geo_coordinates');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('addresses', function (Blueprint $table) {
            $table->dropColumn(['latitude', 'longitude', 'screen_geo_coordinates', 'map_url']);
        });
    }
};
