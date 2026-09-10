PuranPay for WooCommerce
Contributors: puranpay
Tags: woocommerce, payments, bkash, nagad, bangladesh
Requires at least: 6.4
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Accept bKash, Nagad, Rocket, and Upay on WooCommerce through PuranPay hosted checkout.

== Description ==

PuranPay for WooCommerce redirects the customer to the hosted pay page. They Send Money from bKash, Nagad, Rocket, or Upay, type the TrxID, and the official SMS match marks the WooCommerce order paid.

This plugin is a client for the [PuranPay](https://puranpay.com) service. You need a PuranPay account, an active plan, and a paired phone app so official wallet SMS can be matched. The plugin does not lock features behind a license key.

= How it works =

1. Customer places a WooCommerce order and chooses PuranPay.
2. The shop creates a payment with your secret key and redirects to checkoutUrl.
3. After the TrxID matches, PuranPay POSTs `payment.verified` to your webhook.
4. The plugin verifies `x-puranpay-signature` (HMAC-SHA256 of the raw body) and calls `payment_complete`.

Do not treat the browser return as paid. The thank-you page only syncs if the webhook is late.

= Setup =

1. In PuranPay: Billing → buy a plan. Settings → add this shop domain (host only, no `https://`).
2. Settings → Webhook URL → paste the URL shown on the PuranPay gateway settings screen (`/wp-json/puranpay/v1/webhook`).
3. Copy `sk_live_` (or `sk_test_` in Test mode) and the webhook secret into WooCommerce → Settings → Payments → PuranPay.
4. Pair the Android app under Phone app so official SMS can match.

Store currency must be **BDT**. The checkout host and API URL default to PuranPay production (`https://api.puranpay.com`).

= Privacy =

When a customer pays, the plugin sends order amount, order id, and optional billing name / email / phone to PuranPay so checkout and the verified webhook can run. No data is sent until you save a secret key and the customer places an order.

== Installation ==

1. Upload the plugin zip in Plugins → Add New → Upload Plugin, or search for “PuranPay” after it is listed on WordPress.org.
2. Activate it.
3. WooCommerce → Settings → Payments → PuranPay → Enable.
4. Paste the secret key and webhook secret. Copy the webhook URL into the PuranPay dashboard.

== Frequently Asked Questions ==

= Does this work with the block checkout? =

Yes. Classic shortcode checkout and Cart/Checkout blocks are both supported.

= Why was the order not marked paid? =

Check that the webhook URL is HTTPS, the HMAC secret matches, the shop domain is on your plan, and Test mode matches the key (`sk_test_` vs `sk_live_`). Sandbox webhooks have `livemode: false` and are ignored on a live store.

= Can I refund from WooCommerce? =

Not through this plugin. PuranPay does not expose a refund API yet.

== Changelog ==

= 1.0.0 =
* First release: hosted checkout, signed webhook fulfill, Checkout Blocks, HPOS.

== Upgrade Notice ==

= 1.0.0 =
Initial release.
