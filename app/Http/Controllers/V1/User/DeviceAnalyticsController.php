<?php

namespace App\Http\Controllers\V1\User;

use App\Http\Controllers\Controller;
use App\Services\DeviceAnalyticsService;
use Illuminate\Http\Request;

class DeviceAnalyticsController extends Controller
{
    public function heartbeat(Request $request, DeviceAnalyticsService $service)
    {
        $payload = $request->validate([
            'device_id' => 'required|string|max:191',
            'platform' => 'nullable|string|max:32',
            'distribution_channel' => 'nullable|string|max:64',
            'bundle_id' => 'nullable|string|max:191',
            'app_version' => 'nullable|string|max:64',
            'build_number' => 'nullable|string|max:64',
            'device_label' => 'nullable|string|max:191',
        ]);

        $user = $request->user();
        if (!$user) {
            return $this->fail([403, __('Unauthorized')]);
        }

        $service->capture($user, $payload, $request->ip());

        return $this->success([
            'captured' => true,
        ]);
    }
}
