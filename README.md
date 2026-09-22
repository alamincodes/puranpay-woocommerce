# PuranPay for WooCommerce

Official WooCommerce gateway for [PuranPay](https://puranpay.com). Create a checkout from the order, send the customer to hosted pay (bKash, Nagad, Rocket, Upay), fulfill only after a signed `payment.verified` webhook.

Requires WordPress 6.4+, WooCommerce 8.0+, PHP 7.4+, store currency **BDT**. Same API as the Node SDK (`puranpay`). No backend changes.

## Install from zip

1. Zip this folder as `puranpay-for-woocommerce` (it must contain `puranpay-woocommerce.php` at the top level). The directory slug and the text domain are both `puranpay-for-woocommerce`.
2. WordPress → Plugins → Add New → Upload Plugin.
3. Activate. WooCommerce → Settings → Payments → **PuranPay**.

## Connect the shop

On the gateway settings screen the plugin prints the two values PuranPay needs:

| Paste into PuranPay dashboard | Example |
|---|---|
| Settings → shop domain | `yourshop.com` (no `https://`) |
| Settings → Webhook URL | `https://yourshop.com/wp-json/puranpay/v1/webhook` |

Then:

1. Billing → active plan.
2. Copy **secret key** (`sk_test_` while integrating, `sk_live_` in production) and **webhook secret**.
3. Paste both into the WooCommerce gateway. Enable **Test mode** only with `sk_test_`.
4. Pair the phone app so official SMS can match TrxIDs.

`successUrl` / `failUrl` are HTTPS thank-you and pay-again links on this domain. Local HTTP only works against a PuranPay API running in development.

## What the plugin calls

- `POST /api/payments` with `x-api-key`, `x-shop-domain`, `Idempotency-Key: wc-order-{id}`
- Redirect to `checkoutUrl`
- Webhook: HMAC-SHA256 of the **raw** JSON body vs `x-puranpay-signature`
- Optional `GET /api/payments/:id` on the thank-you page if the webhook is late

Fulfillment is skipped when `livemode` does not match Test mode, when amounts differ, or when the order is already paid (`orderId` is the idempotency key for retries).

## File map

```
puranpay-woocommerce.php    boot, HPOS, Blocks, REST
includes/class-puranpay-api.php
includes/class-puranpay-webhook.php
includes/class-wc-puranpay-gateway.php
includes/class-puranpay-blocks.php
assets/js/blocks.js
```

Backend, checkout app, and the npm package stay untouched.
