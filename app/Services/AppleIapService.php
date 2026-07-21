<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Jobs\OrderHandleJob;
use App\Models\AppleIapTransaction;
use App\Models\Order;
use App\Models\Plan;
use App\Models\User;
use App\Utils\Helper;
use Illuminate\Http\Client\Response;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class AppleIapService
{
    public function getClientConfiguration(Request $request): array
    {
        $isIos = strtolower((string) $request->header('X-Client-Platform')) === 'ios';
        $countryCode = $this->resolveCountryCode($request);
        $enabled = (bool) admin_setting('apple_iap_enable', false);
        $products = $this->getConfiguredProducts();
        $useIap = $enabled && $isIos && $countryCode !== null && $countryCode !== 'CN' && $products !== [];

        return [
            'purchase_mode' => $useIap ? 'apple_iap' : 'standard',
            'country_code' => $countryCode,
            'products' => $useIap ? $products : [],
        ];
    }

    public function verifyAndGrant(User $user, string $transactionId, string $clientProductId): array
    {
        $transaction = AppleIapTransaction::where('transaction_id', $transactionId)->first();
        if ($transaction) {
            if ((int) $transaction->user_id !== (int) $user->id) {
                throw new ApiException(__('This Apple transaction has already been used'));
            }
            if ($transaction->status === 'completed') {
                return $this->result($transaction, true);
            }
            if ($transaction->order_id) {
                return $this->completeExisting($transaction);
            }
        }

        [$payload, $environment] = $this->fetchTransaction($transactionId);
        $productId = (string) ($payload['productId'] ?? '');
        $bundleId = (string) ($payload['bundleId'] ?? '');
        $verifiedTransactionId = (string) ($payload['transactionId'] ?? '');

        if ($verifiedTransactionId !== $transactionId || $productId !== $clientProductId) {
            throw new ApiException(__('Apple transaction does not match the purchase'));
        }
        if ($bundleId !== (string) config('apple_iap.bundle_id')) {
            throw new ApiException(__('Apple transaction belongs to another app'));
        }
        if (!empty($payload['revocationDate'])) {
            throw new ApiException(__('Apple transaction has been revoked'));
        }
        if (($payload['type'] ?? null) !== 'Consumable') {
            throw new ApiException(__('Apple product is not a consumable purchase'));
        }

        $mapping = collect($this->getConfiguredProducts())
            ->firstWhere('product_id', $productId);
        if (!$mapping) {
            throw new ApiException(__('Apple product is not enabled'));
        }

        $plan = Plan::find((int) $mapping['plan_id']);
        if (!$plan) {
            throw new ApiException(__('Subscription plan does not exist'));
        }
        $this->validateMappedPlanPurchase($user, $plan, $mapping['period']);

        $transaction = DB::transaction(function () use ($user, $plan, $mapping, $payload, $environment, $transactionId, $bundleId, $productId) {
            $existing = AppleIapTransaction::where('transaction_id', $transactionId)
                ->lockForUpdate()
                ->first();
            if ($existing) {
                if ((int) $existing->user_id !== (int) $user->id) {
                    throw new ApiException(__('This Apple transaction has already been used'));
                }
                return $existing;
            }

            $order = new Order([
                'user_id' => $user->id,
                'plan_id' => $plan->id,
                'period' => $mapping['period'],
                'trade_no' => Helper::generateOrderNo(),
                'total_amount' => (int) (($plan->prices[$mapping['period']] ?? 0) * 100),
                'status' => Order::STATUS_PENDING,
                'type' => ($user->plan_id === $plan->id
                    && ($user->expired_at === null || $user->expired_at > time()))
                    ? Order::TYPE_RENEWAL
                    : Order::TYPE_NEW_PURCHASE,
            ]);
            $orderService = new OrderService($order);
            $orderService->setInvite($user);
            $order->saveOrFail();

            return AppleIapTransaction::create([
                'user_id' => $user->id,
                'order_id' => $order->id,
                'transaction_id' => $transactionId,
                'original_transaction_id' => $payload['originalTransactionId'] ?? null,
                'product_id' => $productId,
                'bundle_id' => $bundleId,
                'environment' => $environment,
                'status' => 'pending',
                'apple_payload' => $payload,
            ]);
        });

        return $this->completeExisting($transaction);
    }

    public function getConfiguredProducts(?int $legacyPlanId = null): array
    {
        $legacyPlanId ??= (int) admin_setting('apple_iap_plan_id', 0);
        $configured = admin_setting('apple_iap_products', config('apple_iap.products', []));
        if (is_string($configured)) {
            $configured = json_decode($configured, true);
        }
        if (!is_array($configured)) {
            return [];
        }

        return collect($configured)
            ->filter(fn($item) => is_array($item) && ($item['enabled'] ?? false))
            ->map(function ($item) use ($legacyPlanId) {
                $productId = (string) ($item['product_id'] ?? '');
                $productPlanId = (int) ($item['plan_id'] ?? 0);
                $productId = match ($productId) {
                    'com.bilink.bilinklink.pass.3months' => 'com.bilink.bilinklink.pass.3month',
                    'com.bilink.bilinklink.pass.6months' => 'com.bilink.bilinklink.pass.6month',
                    'com.bilink.bilinklink.pass.12months' => 'com.bilink.bilinklink.pass.12month',
                    default => $productId,
                };
                return [
                'product_id' => $productId,
                'plan_id' => $productPlanId > 0 ? $productPlanId : $legacyPlanId,
                'period' => (string) ($item['period'] ?? ''),
                'sort' => (int) ($item['sort'] ?? 0),
                ];
            })
            ->filter(fn($item) => $item['product_id'] !== ''
                && $item['plan_id'] > 0
                && in_array($item['period'], PlanService::getNewPeriods(), true))
            ->sortBy('sort')
            ->values()
            ->all();
    }

    private function validateMappedPlanPurchase(User $user, Plan $plan, string $period): void
    {
        $periodKey = PlanService::getPeriodKey($period);
        if (($plan->prices[$periodKey] ?? null) === null) {
            throw new ApiException(__('This payment period cannot be purchased, please choose another period'));
        }

        $planService = new PlanService($plan);
        if ((int) $user->plan_id !== (int) $plan->id && !$planService->hasCapacity($plan)) {
            throw new ApiException(__('Current product is sold out'));
        }

        if ((int) $user->plan_id === (int) $plan->id && !$plan->renew) {
            throw new ApiException(__('This subscription cannot be renewed, please change to another subscription'));
        }
    }

    private function completeExisting(AppleIapTransaction $transaction): array
    {
        $order = Order::find($transaction->order_id);
        if (!$order) {
            throw new ApiException(__('Apple purchase order does not exist'));
        }
        if ($order->status === Order::STATUS_PENDING) {
            if (!(new OrderService($order))->paid($transaction->transaction_id)) {
                throw new ApiException(__('Failed to activate Apple purchase'));
            }
        } elseif ($order->status === Order::STATUS_PROCESSING) {
            try {
                OrderHandleJob::dispatchSync($order->trade_no);
            } catch (\Throwable) {
                throw new ApiException(__('Failed to activate Apple purchase'));
            }
        }
        $order->refresh();
        $transaction->status = $order->status === Order::STATUS_COMPLETED ? 'completed' : 'processing';
        $transaction->save();

        return $this->result($transaction, false);
    }

    private function result(AppleIapTransaction $transaction, bool $duplicate): array
    {
        return [
            'success' => $transaction->status === 'completed',
            'duplicate' => $duplicate,
            'transaction_id' => $transaction->transaction_id,
            'order_id' => $transaction->order_id,
            'status' => $transaction->status,
        ];
    }

    private function fetchTransaction(string $transactionId): array
    {
        $token = $this->makeApiToken();
        $production = $this->requestTransaction('https://api.storekit.apple.com', $transactionId, $token);
        if ($production->successful()) {
            return [$this->decodeSignedPayload($production), 'Production'];
        }

        // TestFlight and sandbox purchases can receive an authorization-style
        // response from the production endpoint even though the same App Store
        // Server API key is valid in the sandbox environment. In that case,
        // retry against sandbox instead of rejecting the purchase prematurely.
        if (in_array($production->status(), [401, 404], true)) {
            $sandbox = $this->requestTransaction('https://api.storekit-sandbox.apple.com', $transactionId, $token);
            if ($sandbox->successful()) {
                return [$this->decodeSignedPayload($sandbox), 'Sandbox'];
            }
        }

        throw new ApiException(__('Apple could not verify this transaction'));
    }

    private function requestTransaction(string $baseUrl, string $transactionId, string $token): Response
    {
        return Http::withToken($token)
            ->acceptJson()
            ->timeout(15)
            ->retry(2, 250, null, false)
            ->get($baseUrl . '/inApps/v1/transactions/' . rawurlencode($transactionId));
    }

    private function decodeSignedPayload(Response $response): array
    {
        $jws = (string) $response->json('signedTransactionInfo');
        $parts = explode('.', $jws);
        if (count($parts) !== 3) {
            throw new ApiException(__('Apple returned invalid transaction data'));
        }
        $payload = json_decode($this->base64UrlDecode($parts[1]), true);
        if (!is_array($payload)) {
            throw new ApiException(__('Apple returned invalid transaction data'));
        }
        return $payload;
    }

    private function makeApiToken(): string
    {
        $issuerId = (string) config('apple_iap.issuer_id');
        $keyId = (string) config('apple_iap.key_id');
        $privateKey = $this->privateKey();
        if ($issuerId === '' || $keyId === '' || $privateKey === '') {
            throw new ApiException(__('Apple in-app purchase credentials are not configured'));
        }

        $now = time();
        $header = $this->base64UrlEncode(json_encode(['alg' => 'ES256', 'kid' => $keyId, 'typ' => 'JWT'], JSON_UNESCAPED_SLASHES));
        $claims = $this->base64UrlEncode(json_encode([
            'iss' => $issuerId,
            'iat' => $now,
            'exp' => $now + 600,
            'aud' => 'appstoreconnect-v1',
            'bid' => config('apple_iap.bundle_id'),
        ], JSON_UNESCAPED_SLASHES));
        $input = $header . '.' . $claims;
        if (!openssl_sign($input, $derSignature, $privateKey, OPENSSL_ALGO_SHA256)) {
            throw new ApiException(__('Unable to sign Apple API request'));
        }

        return $input . '.' . $this->base64UrlEncode($this->derToJose($derSignature, 64));
    }

    private function privateKey(): string
    {
        $value = (string) config('apple_iap.private_key');
        if ($value !== '') {
            return str_replace('\n', "\n", $value);
        }
        $path = (string) config('apple_iap.private_key_path');
        return $path !== '' && is_readable($path) ? (string) file_get_contents($path) : '';
    }

    private function resolveCountryCode(Request $request): ?string
    {
        $header = strtoupper(trim((string) $request->header('CF-IPCountry')));
        if (config('apple_iap.trust_cf_country_header')
            && preg_match('/^[A-Z]{2}$/', $header)
            && !in_array($header, ['XX', 'T1'], true)) {
            return $header;
        }

        $ip = $request->ip();
        if (!$ip || !filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return null;
        }
        try {
            $region = (string) \Ip2Region::getInstance()->search($ip);
            if ($region === '' || str_contains($region, '内网IP')) {
                return null;
            }
            if (preg_match('/^中国\|(香港|澳门|台湾省)\|/', $region)) {
                return 'NON_CN';
            }
            return str_starts_with($region, '中国|') ? 'CN' : 'NON_CN';
        } catch (\Throwable) {
            return null;
        }
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function base64UrlDecode(string $value): string
    {
        return (string) base64_decode(strtr($value . str_repeat('=', (4 - strlen($value) % 4) % 4), '-_', '+/'), true);
    }

    private function derToJose(string $der, int $length): string
    {
        $offset = 2;
        if ((ord($der[1]) & 0x80) !== 0) {
            $offset += ord($der[1]) & 0x7f;
        }
        if (ord($der[$offset]) !== 0x02) {
            throw new ApiException(__('Invalid Apple API signature'));
        }
        $rLength = ord($der[$offset + 1]);
        $r = substr($der, $offset + 2, $rLength);
        $offset += 2 + $rLength;
        if (ord($der[$offset]) !== 0x02) {
            throw new ApiException(__('Invalid Apple API signature'));
        }
        $sLength = ord($der[$offset + 1]);
        $s = substr($der, $offset + 2, $sLength);
        $partLength = intdiv($length, 2);
        return str_pad(ltrim($r, "\0"), $partLength, "\0", STR_PAD_LEFT)
            . str_pad(ltrim($s, "\0"), $partLength, "\0", STR_PAD_LEFT);
    }
}
