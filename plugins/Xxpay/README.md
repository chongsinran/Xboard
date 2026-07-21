# XXPay payment plugin

This plugin integrates Xboard with the XXPay-compatible unified-order API at
`/api/pay/create_order`.

## Setup

1. In **Admin > Plugins**, install and enable `XXPay`.
2. In **Admin > Payment**, create an `XXPay` payment method.
3. Enter the gateway URL, merchant ID, optional app ID, private key and product
   ID supplied by the payment provider.
4. Enable the payment method and copy its generated callback URL if the
   provider requires it to be allow-listed.

Common product IDs:

- `8024`: aggregate QR payment
- `8002`: WeChat QR payment
- `8006`: Alipay QR payment
- `8003`: WeChat H5
- `8007`: Alipay H5
- `8018`: Alipay desktop payment

Amounts are sent in cents. A callback is accepted only when its signature is
valid, its merchant ID matches, and its status is `2` or `3`. The callback
response is the exact lowercase string `success` required by the gateway.

Use an HTTPS gateway URL in production whenever the provider offers one.
