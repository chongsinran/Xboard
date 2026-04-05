<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('v2_user_device', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('user_id')->index();
            $table->string('device_id', 191)->index();
            $table->string('platform', 32)->nullable()->index();
            $table->string('distribution_channel', 64)->nullable()->index();
            $table->string('bundle_id', 191)->nullable()->index();
            $table->string('app_version', 64)->nullable();
            $table->string('build_number', 64)->nullable();
            $table->string('device_label', 191)->nullable();
            $table->string('last_seen_ip', 64)->nullable();
            $table->unsignedInteger('first_seen_at')->nullable();
            $table->unsignedInteger('last_seen_at')->nullable();
            $table->boolean('is_registration_device')->default(false);
            $table->unsignedInteger('created_at')->nullable();
            $table->unsignedInteger('updated_at')->nullable();

            $table->unique(['user_id', 'device_id'], 'v2_user_device_user_device_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('v2_user_device');
    }
};
