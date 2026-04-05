<?php

namespace App\Services;

use App\Models\DailyActiveBundle;
use App\Models\User;
use App\Models\UserDevice;
use Illuminate\Support\Facades\DB;

class DeviceAnalyticsService
{
    public function capture(User $user, array $payload, ?string $ip = null): void
    {
        $deviceId = trim((string) ($payload['device_id'] ?? ''));
        if ($deviceId === '') {
            return;
        }

        $now = time();
        $activityDate = date('Y-m-d', $now);
        $platform = $this->normalize($payload['platform'] ?? null, 'unknown');
        $channel = $this->normalize($payload['distribution_channel'] ?? null, 'unknown');
        $bundleId = $this->normalize($payload['bundle_id'] ?? null, 'unknown');
        $appVersion = $this->normalizeNullable($payload['app_version'] ?? null);
        $buildNumber = $this->normalizeNullable($payload['build_number'] ?? null);
        $deviceLabel = $this->normalizeNullable($payload['device_label'] ?? null);

        if (empty($user->device_id)) {
            $user->device_id = $deviceId;
            $user->save();
        }

        UserDevice::updateOrCreate(
            [
                'user_id' => $user->id,
                'device_id' => $deviceId,
            ],
            [
                'platform' => $platform,
                'distribution_channel' => $channel,
                'bundle_id' => $bundleId,
                'app_version' => $appVersion,
                'build_number' => $buildNumber,
                'device_label' => $deviceLabel,
                'last_seen_ip' => $ip,
                'first_seen_at' => DB::raw('COALESCE(first_seen_at, ' . $now . ')'),
                'last_seen_at' => $now,
                'is_registration_device' => $user->device_id === $deviceId,
            ]
        );

        DailyActiveBundle::updateOrCreate(
            [
                'activity_date' => $activityDate,
                'user_id' => $user->id,
                'device_id' => $deviceId,
                'bundle_id' => $bundleId,
            ],
            [
                'platform' => $platform,
                'distribution_channel' => $channel,
                'app_version' => $appVersion,
                'build_number' => $buildNumber,
                'first_active_at' => DB::raw('COALESCE(first_active_at, ' . $now . ')'),
                'last_active_at' => $now,
            ]
        );
    }

    private function normalize(?string $value, string $fallback): string
    {
        $value = trim((string) $value);
        return $value !== '' ? substr($value, 0, 191) : $fallback;
    }

    private function normalizeNullable(?string $value): ?string
    {
        $value = trim((string) $value);
        return $value !== '' ? substr($value, 0, 191) : null;
    }
}
