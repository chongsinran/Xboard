<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('v2_daily_active_bundle', function (Blueprint $table) {
            $table->increments('id');
            $table->date('activity_date')->index();
            $table->unsignedInteger('user_id')->index();
            $table->string('device_id', 191)->index();
            $table->string('platform', 32)->nullable()->index();
            $table->string('distribution_channel', 64)->nullable()->index();
            $table->string('bundle_id', 191)->nullable()->index();
            $table->string('app_version', 64)->nullable();
            $table->string('build_number', 64)->nullable();
            $table->unsignedInteger('first_active_at')->nullable();
            $table->unsignedInteger('last_active_at')->nullable();
            $table->unsignedInteger('created_at')->nullable();
            $table->unsignedInteger('updated_at')->nullable();

            $table->unique(
                ['activity_date', 'user_id', 'device_id', 'bundle_id'],
                'v2_daily_active_bundle_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('v2_daily_active_bundle');
    }
};
