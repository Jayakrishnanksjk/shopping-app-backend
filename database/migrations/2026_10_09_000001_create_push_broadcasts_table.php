<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('push_broadcasts', function (Blueprint $table) {
            $table->id();
            $table->string('title', 255);
            $table->string('body', 500);
            $table->unsignedBigInteger('image_id')->nullable();
            $table->string('image_url', 1000)->nullable();
            // Optional deep-link: product / category / external url
            $table->string('redirect_type', 50)->nullable();
            $table->string('redirect_target', 1000)->nullable();
            // immediate | once | daily | interval
            $table->enum('schedule_type', ['immediate', 'once', 'daily', 'interval'])->default('immediate');
            $table->dateTime('scheduled_at')->nullable();
            // HH:MM:SS for daily repeats
            $table->time('daily_time')->nullable();
            // minutes between sends for interval repeats
            $table->unsignedInteger('interval_minutes')->nullable();
            $table->dateTime('starts_at')->nullable();
            $table->dateTime('ends_at')->nullable();
            $table->dateTime('next_run_at')->nullable()->index();
            $table->dateTime('last_sent_at')->nullable();
            $table->unsignedBigInteger('sent_count')->default(0);
            // scheduled | active | paused | completed | cancelled
            $table->enum('status', ['scheduled', 'active', 'paused', 'completed', 'cancelled'])->default('scheduled');
            $table->unsignedBigInteger('created_by_id')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('image_id')->references('id')->on('attachments')->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('push_broadcasts');
    }
};
