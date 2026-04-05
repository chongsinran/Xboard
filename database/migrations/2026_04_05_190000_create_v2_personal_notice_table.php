<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('v2_personal_notice')) {
            Schema::create('v2_personal_notice', function (Blueprint $table) {
                $table->integer('id', true);
                $table->integer('user_id')->index();
                $table->string('title');
                $table->text('content');
                $table->string('content_format', 20)->default('markdown');
                $table->string('img_url')->nullable();
                $table->json('tags')->nullable();
                $table->tinyInteger('show')->default(1)->index();
                $table->integer('read_at')->nullable()->index();
                $table->integer('created_at');
                $table->integer('updated_at');

                $table->foreign('user_id')
                    ->references('id')
                    ->on('v2_user')
                    ->onDelete('cascade');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('v2_personal_notice');
    }
};
