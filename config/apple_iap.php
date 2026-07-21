<?php

use App\Models\Plan;

return [
    'bundle_id' => env('APPLE_IAP_BUNDLE_ID', 'com.bilink.bilinklink.pass'),
    'issuer_id' => env('APPLE_IAP_ISSUER_ID'),
    'key_id' => env('APPLE_IAP_KEY_ID'),
    'private_key' => env('APPLE_IAP_PRIVATE_KEY'),
    'private_key_path' => env('APPLE_IAP_PRIVATE_KEY_PATH'),
    'trust_cf_country_header' => env('APPLE_IAP_TRUST_CF_COUNTRY_HEADER', false),
    'products' => [
        [
            'product_id' => 'com.bilink.bilinklink.pass.1month',
            'plan_id' => 0,
            'period' => Plan::PERIOD_MONTHLY,
            'enabled' => true,
            'sort' => 1,
        ],
        [
            'product_id' => 'com.bilink.bilinklink.pass.3month',
            'plan_id' => 0,
            'period' => Plan::PERIOD_QUARTERLY,
            'enabled' => true,
            'sort' => 2,
        ],
        [
            'product_id' => 'com.bilink.bilinklink.pass.6month',
            'plan_id' => 0,
            'period' => Plan::PERIOD_HALF_YEARLY,
            'enabled' => true,
            'sort' => 3,
        ],
        [
            'product_id' => 'com.bilink.bilinklink.pass.12month',
            'plan_id' => 0,
            'period' => Plan::PERIOD_YEARLY,
            'enabled' => true,
            'sort' => 4,
        ],
    ],
];
