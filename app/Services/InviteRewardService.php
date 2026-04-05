<?php

namespace App\Services;

use App\Models\Order;
use App\Models\User;
use App\Models\UserInviteRewardLog;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class InviteRewardService
{
    private const BYTES_PER_GIGABYTE = 1073741824;

    public function markUsersValidByTrafficIds(array $userIds): void
    {
        $userIds = collect($userIds)
            ->map(static fn ($id) => (int) $id)
            ->filter(static fn ($id) => $id > 0)
            ->unique()
            ->values();

        if ($userIds->isEmpty()) {
            return;
        }

        User::query()
            ->whereIn('id', $userIds->all())
            ->whereNotNull('invite_user_id')
            ->whereNull('invite_valid_at')
            ->whereRaw('(u + d) > 0')
            ->get()
            ->each(function (User $invitee) {
                $this->markInviteeValid($invitee);
            });
    }

    public function markInviteeValid(User $invitee): void
    {
        if (!$invitee->invite_user_id || $invitee->invite_valid_at) {
            return;
        }

        DB::transaction(function () use ($invitee) {
            $freshInvitee = User::lockForUpdate()->find($invitee->id);
            if (!$freshInvitee || !$freshInvitee->invite_user_id || $freshInvitee->invite_valid_at) {
                return;
            }

            if (($freshInvitee->u ?? 0) + ($freshInvitee->d ?? 0) <= 0) {
                return;
            }

            $freshInvitee->invite_valid_at = time();
            $freshInvitee->save();

            $inviter = User::lockForUpdate()->find($freshInvitee->invite_user_id);
            if (!$inviter) {
                return;
            }

            $this->grantPerValidInviteReward($inviter, $freshInvitee);
            $this->grantMilestones($inviter);
        });
    }

    public function markInviteePaid(User $invitee, ?Order $order = null): void
    {
        if (!$invitee->invite_user_id || $invitee->invite_paid_at) {
            return;
        }

        DB::transaction(function () use ($invitee, $order) {
            $freshInvitee = User::lockForUpdate()->find($invitee->id);
            if (!$freshInvitee || !$freshInvitee->invite_user_id || $freshInvitee->invite_paid_at) {
                return;
            }

            $hasPaidOrder = $order
                ? ((int) $order->status === Order::STATUS_COMPLETED && (int) $order->total_amount > 0)
                : Order::query()
                    ->where('user_id', $freshInvitee->id)
                    ->where('status', Order::STATUS_COMPLETED)
                    ->where('total_amount', '>', 0)
                    ->exists();

            if (!$hasPaidOrder) {
                return;
            }

            if (!$freshInvitee->invite_valid_at && (($freshInvitee->u ?? 0) + ($freshInvitee->d ?? 0) > 0)) {
                $freshInvitee->invite_valid_at = time();
            }

            $freshInvitee->invite_paid_at = time();
            $freshInvitee->save();

            $inviter = User::lockForUpdate()->find($freshInvitee->invite_user_id);
            if (!$inviter) {
                return;
            }

            if ($freshInvitee->invite_valid_at) {
                $this->grantPerValidInviteReward($inviter, $freshInvitee);
            }
            $this->grantMilestones($inviter);
        });
    }

    public function getInviteProgress(User $inviter): array
    {
        $validCount = User::query()
            ->where('invite_user_id', $inviter->id)
            ->whereNotNull('invite_valid_at')
            ->count();
        $paidCount = User::query()
            ->where('invite_user_id', $inviter->id)
            ->whereNotNull('invite_paid_at')
            ->count();

        $levels = $this->getLevelConfigs();

        return [
            'valid_invite_count' => $validCount,
            'paid_invite_count' => $paidCount,
            'current_level' => $this->resolveCurrentLevel($validCount, $paidCount, $levels),
            'levels' => array_map(function (array $level) use ($inviter, $validCount, $paidCount) {
                $rewardKey = $level['reward_key'];
                $completed = match ($level['mode']) {
                    'per_valid' => false,
                    default => UserInviteRewardLog::query()
                        ->where('inviter_user_id', $inviter->id)
                        ->where('reward_key', $rewardKey)
                        ->exists(),
                };

                return [
                    ...$level,
                    'progress_valid' => min($validCount, (int) ($level['valid_target'] ?: $validCount)),
                    'progress_paid' => min($paidCount, (int) ($level['paid_target'] ?: $paidCount)),
                    'completed' => $completed,
                ];
            }, $levels),
        ];
    }

    private function grantPerValidInviteReward(User $inviter, User $invitee): void
    {
        $level = $this->getLevelConfigs()[0];
        if (!(bool) $level['enabled']) {
            return;
        }

        $rewardKey = sprintf('invite_level_a_valid_%d', $invitee->id);
        if (UserInviteRewardLog::query()
            ->where('inviter_user_id', $inviter->id)
            ->where('reward_key', $rewardKey)
            ->exists()) {
            return;
        }

        $rewardBytes = $this->gigabytesToBytes((float) $level['reward_transfer_gb']);
        $rewardHours = (float) $level['reward_hours'];

        $this->applyReward($inviter, $level['reward_type'], $level['reward_value'], $rewardBytes, $rewardHours);
        $this->createRewardLog(
            inviter: $inviter,
            rewardKey: $rewardKey,
            levelKey: $level['key'],
            rewardName: $level['title'],
            invitee: $invitee,
            snapshot: $level,
        );
    }

    private function grantMilestones(User $inviter): void
    {
        $validCount = User::query()
            ->where('invite_user_id', $inviter->id)
            ->whereNotNull('invite_valid_at')
            ->count();
        $paidCount = User::query()
            ->where('invite_user_id', $inviter->id)
            ->whereNotNull('invite_paid_at')
            ->count();

        foreach (array_slice($this->getLevelConfigs(), 1) as $level) {
            if (!(bool) $level['enabled']) {
                continue;
            }

            if ($validCount < (int) $level['valid_target'] || $paidCount < (int) $level['paid_target']) {
                continue;
            }

            if (UserInviteRewardLog::query()
                ->where('inviter_user_id', $inviter->id)
                ->where('reward_key', $level['reward_key'])
                ->exists()) {
                continue;
            }

            $this->applyReward($inviter, $level['reward_type'], $level['reward_value']);
            $this->createRewardLog(
                inviter: $inviter,
                rewardKey: $level['reward_key'],
                levelKey: $level['key'],
                rewardName: $level['title'],
                invitee: null,
                snapshot: [
                    ...$level,
                    'valid_count' => $validCount,
                    'paid_count' => $paidCount,
                ],
            );
        }
    }

    private function applyReward(
        User $user,
        string $rewardType,
        int $rewardValue,
        int $rewardBytes = 0,
        float $rewardHours = 0,
    ): void {
        if ($rewardType === 'traffic_hours') {
            if ($rewardBytes > 0) {
                $user->transfer_enable = (int) ($user->transfer_enable ?? 0) + $rewardBytes;
            }

            if ($rewardHours > 0 && $user->expired_at !== null) {
                $user->expired_at = max((int) $user->expired_at, time()) + (int) round($rewardHours * 3600);
            }

            $user->save();
            return;
        }

        if ($rewardType === 'lifetime') {
            $user->expired_at = null;
            $user->save();
            return;
        }

        $base = $user->expired_at ? Carbon::createFromTimestamp(max((int) $user->expired_at, time())) : Carbon::now();

        $next = match ($rewardType) {
            'days' => $base->copy()->addDays($rewardValue),
            'months' => $base->copy()->addMonthsNoOverflow($rewardValue),
            'years' => $base->copy()->addYearsNoOverflow($rewardValue),
            'hours' => $base->copy()->addHours($rewardValue),
            default => $base,
        };

        $user->expired_at = $next->timestamp;
        $user->save();
    }

    private function createRewardLog(
        User $inviter,
        string $rewardKey,
        string $levelKey,
        string $rewardName,
        ?User $invitee,
        array $snapshot,
    ): void {
        UserInviteRewardLog::query()->create([
            'inviter_user_id' => $inviter->id,
            'invitee_user_id' => $invitee?->id,
            'reward_key' => $rewardKey,
            'level_key' => $levelKey,
            'reward_name' => $rewardName,
            'reward_snapshot' => $snapshot,
        ]);

        Log::info('[InviteReward] Granted', [
            'inviter_user_id' => $inviter->id,
            'invitee_user_id' => $invitee?->id,
            'reward_key' => $rewardKey,
            'level_key' => $levelKey,
        ]);
    }

    private function resolveCurrentLevel(int $validCount, int $paidCount, array $levels): string
    {
        $current = 'A';
        foreach ($levels as $level) {
            if ($level['mode'] === 'per_valid') {
                continue;
            }

            if ($validCount >= (int) $level['valid_target'] && $paidCount >= (int) $level['paid_target']) {
                $current = $level['key'];
            }
        }

        return $current;
    }

    private function gigabytesToBytes(float $value): int
    {
        return max(0, (int) round($value * self::BYTES_PER_GIGABYTE));
    }

    private function getLevelConfigs(): array
    {
        return [
            [
                'key' => 'A',
                'title' => '普通推廣會員',
                'badge' => 'Starter',
                'mode' => 'per_valid',
                'enabled' => (bool) admin_setting('invite_level_a_enable', 1),
                'valid_target' => 1,
                'paid_target' => 0,
                'reward_type' => 'traffic_hours',
                'reward_transfer_gb' => (float) admin_setting('invite_level_a_reward_transfer_gb', 1),
                'reward_hours' => (float) admin_setting('invite_level_a_reward_hours', 24),
                'reward_value' => 0,
                'reward_key' => 'invite_level_a',
                'requirement' => '所有註冊用戶均可參與',
                'reward_description' => '每成功推薦 1 位有效用戶，可獲 24 小時 VIP + 1GB 流量',
            ],
            [
                'key' => 'B',
                'title' => '易連分享官',
                'badge' => 'Silver',
                'mode' => 'milestone',
                'enabled' => (bool) admin_setting('invite_level_b_enable', 1),
                'valid_target' => (int) admin_setting('invite_level_b_valid_target', 10),
                'paid_target' => (int) admin_setting('invite_level_b_paid_target', 1),
                'reward_type' => (string) admin_setting('invite_level_b_reward_type', 'months'),
                'reward_value' => (int) admin_setting('invite_level_b_reward_value', 1),
                'reward_key' => 'invite_level_b_milestone',
                'requirement' => '成功邀請 10 位有效用戶，其中包含 1 位付費用戶',
                'reward_description' => '獲得 1 個月 VIP 會員',
            ],
            [
                'key' => 'C',
                'title' => '易連推廣大使',
                'badge' => 'Gold',
                'mode' => 'milestone',
                'enabled' => (bool) admin_setting('invite_level_c_enable', 1),
                'valid_target' => (int) admin_setting('invite_level_c_valid_target', 50),
                'paid_target' => (int) admin_setting('invite_level_c_paid_target', 10),
                'reward_type' => (string) admin_setting('invite_level_c_reward_type', 'years'),
                'reward_value' => (int) admin_setting('invite_level_c_reward_value', 1),
                'reward_key' => 'invite_level_c_milestone',
                'requirement' => '成功邀請 50 位有效用戶，其中包含 10 位付費用戶',
                'reward_description' => '獲得 1 年 VIP 會員',
            ],
            [
                'key' => 'D',
                'title' => '易連合夥人',
                'badge' => 'Platinum',
                'mode' => 'milestone',
                'enabled' => (bool) admin_setting('invite_level_d_enable', 1),
                'valid_target' => (int) admin_setting('invite_level_d_valid_target', 100),
                'paid_target' => (int) admin_setting('invite_level_d_paid_target', 30),
                'reward_type' => (string) admin_setting('invite_level_d_reward_type', 'lifetime'),
                'reward_value' => (int) admin_setting('invite_level_d_reward_value', 1),
                'reward_key' => 'invite_level_d_milestone',
                'requirement' => '成功邀請 100 位有效用戶，其中包含 30 位付費用戶',
                'reward_description' => '享有終身免費使用易連 VPN 的權利',
            ],
        ];
    }
}
