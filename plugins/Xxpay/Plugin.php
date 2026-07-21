<?php

namespace Plugin\Xxpay;

use App\Contracts\PaymentInterface;
use App\Exceptions\ApiException;
use App\Services\Plugin\AbstractPlugin;
use Curl\Curl;

class Plugin extends AbstractPlugin implements PaymentInterface
{
    public function boot(): void
    {
        $this->filter('available_payment_methods', function (array $methods): array {
            if ($this->getConfig('enabled', true)) {
                $methods['XXPay'] = [
                    'name' => $this->getConfig('display_name', '聚合支付'),
                    'icon' => $this->getConfig('icon', '💳'),
                    'plugin_code' => $this->getPluginCode(),
                    'type' => 'plugin',
                ];
            }

            return $methods;
        });
    }

    public function form(): array
    {
        return [
            'gateway_url' => [
                'label' => '支付网关地址',
                'type' => 'string',
                'required' => true,
                'default' => 'http://47.57.180.36:53400',
                'description' => '网关根地址，或完整的 /api/pay/create_order 地址',
            ],
            'mch_id' => [
                'label' => '商户ID',
                'type' => 'string',
                'required' => true,
                'description' => '支付中心分配的 mchId',
            ],
            'app_id' => [
                'label' => '应用ID',
                'type' => 'string',
                'description' => '可选；商户应用的 appId',
            ],
            'private_key' => [
                'label' => '商户私钥',
                'type' => 'string',
                'required' => true,
                'description' => '安全中心显示的签名 key，请勿泄露',
            ],
            'product_id' => [
                'label' => '支付产品ID',
                'type' => 'string',
                'required' => true,
                'default' => '8024',
                'description' => '推荐 8024 聚合码；微信扫码 8002；支付宝扫码 8006',
            ],
            'subject' => [
                'label' => '商品主题',
                'type' => 'string',
                'required' => true,
                'default' => '订阅服务',
                'description' => '最多 64 个字符',
            ],
            'body' => [
                'label' => '商品描述',
                'type' => 'string',
                'required' => true,
                'default' => '在线订阅订单',
                'description' => '最多 256 个字符',
            ],
            'timeout' => [
                'label' => '请求超时（秒）',
                'type' => 'string',
                'default' => '15',
                'description' => '建议 5–30 秒',
            ],
        ];
    }

    public function pay($order): array
    {
        $this->assertRequiredConfig();

        $params = [
            'mchId' => (string) $this->getConfig('mch_id'),
            'productId' => (string) $this->getConfig('product_id', '8024'),
            'mchOrderNo' => (string) $order['trade_no'],
            'amount' => (int) $order['total_amount'],
            'currency' => 'cny',
            'notifyUrl' => (string) $order['notify_url'],
            'returnUrl' => (string) $order['return_url'],
            'subject' => mb_substr((string) $this->getConfig('subject', '订阅服务'), 0, 64),
            'body' => mb_substr((string) $this->getConfig('body', '在线订阅订单'), 0, 256),
            'reqTime' => date('YmdHis'),
            'version' => '1.0',
        ];

        $appId = trim((string) $this->getConfig('app_id', ''));
        if ($appId !== '') {
            $params['appId'] = $appId;
        }

        $params['sign'] = $this->makeSignature($params);
        $result = $this->createOrder($params);

        if ((string) ($result['retCode'] ?? '') !== '0') {
            throw new ApiException((string) ($result['retMsg'] ?? '支付网关下单失败'));
        }
        if (!$this->verifySignature($result)) {
            throw new ApiException('支付网关响应签名验证失败');
        }

        $payUrl = $this->resolvePayUrl($result);
        if ($payUrl === null) {
            throw new ApiException('支付网关未返回可跳转的支付地址');
        }

        return ['type' => 1, 'data' => $payUrl];
    }

    public function notify($params): array|bool
    {
        if (!is_array($params) || !$this->verifySignature($params)) {
            return false;
        }
        if (!hash_equals((string) $this->getConfig('mch_id'), (string) ($params['mchId'] ?? ''))) {
            return false;
        }
        if (!in_array((string) ($params['status'] ?? ''), ['2', '3'], true)) {
            return false;
        }

        $tradeNo = trim((string) ($params['mchOrderNo'] ?? ''));
        $callbackNo = trim((string) ($params['payOrderId'] ?? $params['channelOrderNo'] ?? ''));
        if ($tradeNo === '' || $callbackNo === '') {
            return false;
        }

        return [
            'trade_no' => $tradeNo,
            'callback_no' => $callbackNo,
            'custom_result' => 'success',
        ];
    }

    /** @internal Exposed for deterministic signature tests. */
    public function makeSignature(array $params): string
    {
        unset($params['sign']);
        $params = array_filter($params, static fn ($value): bool => $value !== null && $value !== '');
        ksort($params, SORT_STRING);

        $pairs = [];
        foreach ($params as $key => $value) {
            $pairs[] = $key . '=' . $this->signatureValue($value);
        }

        $pairs[] = 'key=' . (string) $this->getConfig('private_key');

        return strtoupper(md5(implode('&', $pairs)));
    }

    private function verifySignature(array $params): bool
    {
        $provided = strtoupper(trim((string) ($params['sign'] ?? '')));

        return $provided !== '' && hash_equals($this->makeSignature($params), $provided);
    }

    private function createOrder(array $params): array
    {
        $curl = new Curl();
        $timeout = max(1, min(60, (int) $this->getConfig('timeout', 15)));
        $curl->setConnectTimeout(min(10, $timeout));
        $curl->setTimeout($timeout);
        $curl->setHeader('Content-Type', 'application/x-www-form-urlencoded');
        $curl->post($this->createOrderUrl(), http_build_query($params, '', '&', PHP_QUERY_RFC3986));

        $response = $curl->response;
        $error = $curl->error;
        $errorMessage = $curl->errorMessage;
        $curl->close();

        if ($error) {
            throw new ApiException('支付网关请求失败：' . ($errorMessage ?: '网络异常'));
        }

        if (is_object($response)) {
            $response = (array) $response;
        } elseif (is_string($response)) {
            $response = json_decode($response, true);
        }

        if (!is_array($response)) {
            throw new ApiException('支付网关返回了无效的 JSON');
        }

        return $response;
    }

    private function createOrderUrl(): string
    {
        $url = rtrim(trim((string) $this->getConfig('gateway_url')), '/');

        return str_ends_with($url, '/api/pay/create_order')
            ? $url
            : $url . '/api/pay/create_order';
    }

    private function resolvePayUrl(array $result): ?string
    {
        foreach (['payJumpUrl', 'payUrl', 'codeImgUrl', 'codeUrl'] as $field) {
            $candidate = trim((string) ($result[$field] ?? ''));
            if ($candidate !== '' && filter_var($candidate, FILTER_VALIDATE_URL)) {
                return $candidate;
            }
        }

        return null;
    }

    private function signatureValue(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_array($value) || is_object($value)) {
            return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
        }

        return (string) $value;
    }

    private function assertRequiredConfig(): void
    {
        foreach (['gateway_url', 'mch_id', 'private_key', 'product_id'] as $key) {
            if (trim((string) $this->getConfig($key, '')) === '') {
                throw new ApiException("XXPay 配置缺少 {$key}");
            }
        }
    }
}
