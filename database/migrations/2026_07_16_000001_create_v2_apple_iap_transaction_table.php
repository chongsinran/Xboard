<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('v2_apple_iap_transaction', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->index();
            $table->unsignedBigInteger('order_id')->nullable()->index();
            $table->string('transaction_id', 128)->unique();
            $table->string('original_transaction_id', 128)->nullable()->index();
            $table->string('product_id', 191)->index();
            $table->string('bundle_id', 191);
            $table->string('environment', 32)->nullable();
            $table->string('status', 32)->default('pending')->index();
            $table->json('apple_payload')->nullable();
            $table->unsignedBigInteger('created_at');
            $table->unsignedBigInteger('updated_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('v2_apple_iap_transaction');
    }
};
