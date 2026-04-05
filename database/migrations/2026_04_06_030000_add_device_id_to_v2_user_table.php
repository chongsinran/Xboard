<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('v2_user', function (Blueprint $table) {
            if (!Schema::hasColumn('v2_user', 'device_id')) {
                $table->string('device_id', 191)->nullable()->after('uuid');
                $table->unique('device_id', 'v2_user_device_id_unique');
            }
        });
    }

    public function down(): void
    {
        Schema::table('v2_user', function (Blueprint $table) {
            if (Schema::hasColumn('v2_user', 'device_id')) {
                $table->dropUnique('v2_user_device_id_unique');
                $table->dropColumn('device_id');
            }
        });
    }
};
