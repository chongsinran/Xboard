<?php

namespace App\Http\Controllers\V1\Guest;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class AppController extends Controller
{
    /**
     * Public application download metadata used by the marketing website.
     *
     * This deliberately exposes only version labels and download URLs. All
     * management remains protected by the admin configuration routes.
     */
    public function downloads(): JsonResponse
    {
        return response()->json([
            'status' => 'success',
            'data' => [
                'windows_version' => admin_setting('windows_version', ''),
                'windows_download_url' => admin_setting('windows_download_url', ''),
                'macos_version' => admin_setting('macos_version', ''),
                'macos_download_url' => admin_setting('macos_download_url', ''),
                'ios_version' => admin_setting('ios_version', ''),
                'ios_download_url' => admin_setting('ios_download_url', ''),
                'android_version' => admin_setting('android_version', ''),
                'android_download_url' => admin_setting('android_download_url', ''),
            ],
        ]);
    }
}
