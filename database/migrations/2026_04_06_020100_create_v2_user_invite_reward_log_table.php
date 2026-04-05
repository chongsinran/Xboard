<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('v2_user_invite_reward_log', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('inviter_user_id')->index()->comment('邀请人ID');
            $table->integer('invitee_user_id')->nullable()->index()->comment('被邀请人ID');
            $table->string('reward_key', 120)->comment('奖励唯一键');
            $table->string('level_key', 32)->nullable()->comment('等级键');
            $table->string('reward_name', 120)->nullable()->comment('奖励名称');
            $table->json('reward_snapshot')->nullable()->comment('奖励快照');
            $table->integer('created_at')->nullable();
            $table->integer('updated_at')->nullable();

            $table->unique(['inviter_user_id', 'reward_key'], 'uniq_v2_invite_reward_inviter_key');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('v2_user_invite_reward_log');
    }
};
