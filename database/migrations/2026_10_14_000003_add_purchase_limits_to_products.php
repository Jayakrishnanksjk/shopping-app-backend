<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up()
    {
        Schema::table("products", function (Blueprint $table) {
            $table->integer("maximum_purchase_limit")->nullable()->default(null);
            $table->integer("minimum_purchase_limit")->nullable()->default(null);
        });
    }

    public function down()
    {
        Schema::table("products", function (Blueprint $table) {
            $table->dropColumn("maximum_purchase_limit");
            $table->dropColumn("minimum_purchase_limit");
        });
    }
};