<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('v2_user', function (Blueprint $table) {
            if (!Schema::hasColumn('v2_user', 'invite_valid_at')) {
                $table->integer('invite_valid_at')->nullable()->after('invite_user_id')
                    ->comment('邀请用户首次成为有效用户时间');
            }

            if (!Schema::hasColumn('v2_user', 'invite_paid_at')) {
                $table->integer('invite_paid_at')->nullable()->after('invite_valid_at')
                    ->comment('邀请用户首次成为付费用户时间');
            }

            $table->index('invite_valid_at', 'idx_v2_user_invite_valid_at');
            $table->index('invite_paid_at', 'idx_v2_user_invite_paid_at');
        });
    }

    public function down(): void
    {
        Schema::table('v2_user', function (Blueprint $table) {
            if (Schema::hasColumn('v2_user', 'invite_valid_at')) {
                $table->dropIndex('idx_v2_user_invite_valid_at');
                $table->dropColumn('invite_valid_at');
            }

            if (Schema::hasColumn('v2_user', 'invite_paid_at')) {
                $table->dropIndex('idx_v2_user_invite_paid_at');
                $table->dropColumn('invite_paid_at');
            }
        });
    }
};
