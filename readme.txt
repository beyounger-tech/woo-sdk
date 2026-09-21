=== Woo Beyounger Payment ===
Contributors: carterchenrj
Tags: woocommerce, payment, beyoungerpay
Requires at least: 6.0
Tested up to: 6.6
Requires PHP: 7.4
Stable tag: 1.0.9
License: GPLv2 or later

BeyoungerPay tokenized direct payment gateway for WooCommerce.

== Description ==

Shipment tracking synchronization: changes to `_wc_shipment_tracking_items` enqueue background jobs for BeyoungerPay orders. New and changed items are posted to `/gateway/service/shipment`; deleted items are retained in the payment system. There is no historical scan or manual order action. Deploy the gateway shipment endpoint and source-ID/version schema migration first.

The integration listens to WordPress post-meta and WooCommerce order-meta events, coalesces events within each request, and serializes work per order. It sends at most five changed items per job, with up to eight delayed retries (60 seconds, 180 seconds, 540 seconds, then increasing to a six-hour cap). Retries read the current order data. Background timing depends on the site's Action Scheduler/WP-Cron runner; this is asynchronous, not a guarantee of immediate delivery.

New payment attempts store their environment and merchant email on the order. Orders without a saved environment default to live for shipment synchronization, regardless of the current plugin environment setting. There is no automatic fallback to sandbox; a successful sync saves the chosen environment. Shipment status and delivery time are not inferred from tracking numbers. Errors are logged under `beyounger-shipment-sync` without credentials or complete payloads. Orders awaiting a payment transaction number resume when it is recorded.

The official Shipment Tracking formatter (`WC_Shipment_Tracking_Actions::get_instance()->get_tracking_items`) is used when available. Built-in carrier links must come from the formatter; unsupported versions log an error rather than inventing a URL. Custom carrier names receive a deterministic `custom_` code. Source tracking IDs must be present. Verify the installed Shipment Tracking/ShipStation version on a staging site; this repository does not include the paid extension.

This plugin adds a BeyoungerPay payment method to WooCommerce.

Implemented flows:

* Create payment transactions through BeyoungerPay direct payment API.
* For credit cards, render a Beyounger-hosted iframe card form and submit only `card[token]` to WordPress.
* Show multiple BeyoungerPay payment methods at checkout.
* Supports both WooCommerce classic checkout and Checkout Blocks.
* Supports UTM whitelist visibility rules.
* Supports per-payment-method single-order amount limits.
* Supports per-payment-method daily successful payment limits.
* Returning customers with a paid order older than 30 days and no refunds bypass UTM restrictions.
* Verify asynchronous notifications with SHA-256 signatures.
* Update WooCommerce orders for paid, failed, refunding, refunded, complaint, chargeback, and chargeback_reverse states.
* Submit WooCommerce refunds to BeyoungerPay.
* Supports sandbox and live credentials.

The plugin uses BeyoungerPay hosted card tokenization for credit cards. WordPress receives only the card token and never receives card numbers, expiry dates, or CVV values.

== Installation ==

1. Copy the `woo-beyounger-payment` directory into `wp-content/plugins/`.
2. Activate "Woo Beyounger Payment" in WordPress admin.
3. Go to WooCommerce > Settings > Payments > BeyoungerPay.
4. Enter merchant email and the API key for the selected environment.
5. Enable the payment method.

== BeyoungerPay Settings ==

Notification URL:

`https://your-domain.com/wc-api/wc_gateway_beyounger/`

The plugin sends this URL automatically as `notify_url` when creating a transaction.

Browser callback URL:

The plugin sends the WooCommerce order payment URL as `callback_url`, for example `https://your-domain.com/checkout/order-pay/123/?pay_for_order=true&key=wc_order_xxx`. This lets customers return to the same order and retry payment after backing out of Apple Pay, Google Pay, PayPal, Cash App, Card to crypto, or 3DS flows.

If the upstream browser redirect drops WooCommerce's order key but still returns the Beyounger `order_id`, the plugin repairs the callback before WooCommerce renders the page. Paid orders are redirected to the order received page, and unpaid orders are redirected back to the keyed order payment page.

UTM visibility:

Preferred Channel methods require a matching UTM whitelist rule or an eligible returning customer. Standard Channel also allows other visitors; all other availability checks and amount limits still apply. Each payment method has its own Channel setting, defaulting to Preferred Channel. One source is configured per line:

`google`

`facebook`

`partner-*`

The plugin treats bare values as `utm_source` values and generates test links in the settings page. Advanced rules are still supported:

`utm_source=google&utm_campaign=spring`

Only matching UTM values are stored for checkout. A non-whitelisted UTM visit clears the stored UTM marker. Direct visits do not overwrite the stored marker. Returning customers are always allowed when they have a paid order older than 30 days and that qualifying order has no refunds.

Order traffic metadata:

When a BeyoungerPay transaction is created, the plugin stores the traffic context on the WooCommerce order as order meta only. It is not shown on the WP order screen by default:

* `_beyounger_traffic_source_type`: `returning_customer`, `utm_source`, `referer`, or `unknown`
* `_beyounger_traffic_source`: returning customer marker, UTM source value, referer host, or `unknown`
* `_beyounger_is_returning_customer`: `yes` or `no`
* `_beyounger_cus_fd14`: exact customer-type flag sent to BeyoungerPay (`1` for returning customer, `0` for new customer)
* `_beyounger_utm_source`, `_beyounger_utm_medium`, `_beyounger_utm_campaign`, `_beyounger_utm_term`, `_beyounger_utm_content`
* `_beyounger_utm`: JSON snapshot of captured UTM values
* `_beyounger_raw_utm`: JSON snapshot of all captured UTM values, including non-whitelisted sources
* `_beyounger_raw_utm_source`: raw `utm_source`, including non-whitelisted sources
* `_beyounger_has_referer`: `yes` or `no`
* `_beyounger_referer`: captured external referrer URL
* `_beyounger_referer_host`: captured external referrer host

The same traffic context is also sent to BeyoungerPay in the transaction `extra` field as a JSON string:

The top-level transaction parameter `CUSTOM_FD14` is also sent as `1` for a returning customer and `0` for a new customer. It uses the same returning-customer rule as the UTM gate: a paid order older than 30 days with no refund activity.

```json
{
  "plugin": {
    "name": "woo-beyounger-payment",
    "version": "1.0.8"
  },
  "wordpress": {
    "site_url": "https://merchant.example/",
    "order_id": "123"
  },
  "traffic": {
    "source_type": "utm_source",
    "source": "google",
    "is_returning_customer": false,
    "utm": {
      "utm_source": "google"
    },
    "raw_utm": {
      "utm_source": "google"
    },
    "referer": "https://example-referrer.com/page",
    "referer_host": "example-referrer.com"
  }
}
```

The `extra` field does not include card data or API keys.

Beyounger config sync APIs:

The plugin keeps the existing local WP settings behavior and also exposes signed REST APIs for Beyounger to pull or update non-secret plugin configuration.

Endpoints:

* `GET /wp-json/beyounger/v1/config`: pull current config
* `POST|PUT|PATCH /wp-json/beyounger/v1/config`: update config

Required headers:

* `X-Beyounger-Trade-Email`
* `X-Beyounger-Request-Time`
* `X-Beyounger-Nonce`
* `X-Beyounger-Sign`

Signature:

`sha256(secret + METHOD + ROUTE + request_time + nonce + trade_email + sha256(raw_body))`

The `secret` is the Config Sync Secret setting. If it is empty, the plugin falls back to the active environment API key. API keys and the sync secret are never returned by the config pull endpoint; only boolean flags such as `has_live_api_key` are returned.

The update endpoint only accepts non-secret business configuration such as BeyoungerPay enablement, UTM whitelist, payment method titles/descriptions, single-order amount ranges, and daily successful payment limits. It does not allow remote updates to API keys, Config Sync Secret, merchant email, callback base URL, or environment. Merchant email is only used from the signed request header to verify that the request targets the correct merchant site.

Example update body:

```json
{
  "config_version": 12,
  "updated_at": 1786617400,
  "config": {
    "enabled": "yes",
    "utm_whitelist": "google\nfacebook",
    "enabled_card": "yes",
    "max_order_amount_card": "500",
    "successful_payment_limit_card": "5000"
  }
}
```

Daily successful payment limits:

Each BeyoungerPay method has its own single-order amount range. Credit Card, PayPal, Google Pay, Cash App, Apple Pay, and Card to crypto default to 0 to 500. When the current order total is outside a method's range, that method is hidden from checkout. Leave either the minimum or maximum amount empty to disable that side of the range for that payment method.

Each BeyoungerPay method has its own daily successful payment limit. Credit Card, PayPal, Google Pay, Cash App, Apple Pay, and Card to crypto default to 5000. When a method's total successful paid amount exceeds that method's daily limit, that method is hidden from checkout for the rest of the day. Refunded amounts are subtracted from the successful paid total. Leave a limit empty to disable the limit for that payment method.

Card to crypto:

Card to crypto uses the Credit Card icon and redirects to the payment URL returned by BeyoungerPay. It does not display the hosted card form or submit card[token]. Transaction parameters are method_type=10, pay_method=C01, request_type=1, and 3ds_mode=1 by default.

It is disabled by default, with a single-order range of 0 to 500 and a daily successful payment limit of 5000. It shares the existing credentials, environment, UTM and returning-customer rules, notification handling, and refund flow.

The signed config GET and POST/PUT/PATCH endpoints support these Card to crypto fields:

* enabled_card_to_crypto
* title_card_to_crypto
* description_card_to_crypto
* min_order_amount_card_to_crypto
* max_order_amount_card_to_crypto
* successful_payment_limit_card_to_crypto

== Signature Reference ==

Payment:

`sha256(api_key + request_time + trade_email + order_id + pay_method + amount + currency)`

Notification:

`sha256(api_key + request_time + trade_email + transaction_no + status + pay_method + gateway_amount + gateway_currency)`

Refund:

`sha256(api_key + request_time + trade_email + transaction_no + amount)`

== Changelog ==

= 1.0.9 =
* Add independent Preferred Channel and Standard Channel settings for all six payment methods.
* Preserve returning-customer (CUSTOM_FD14=1) and whitelist (CUSTOM_FD14=0) priority; use CUSTOM_FD14=2 only for Standard Channel fallback traffic.
* Support channel settings in signed configuration sync.

= 1.0.8 =
* Add Card to crypto with classic and Blocks checkout, independent settings and limits, refunds, and signed config read/update support.

= 1.0.7 =
* Send `CUSTOM_FD14=1` for returning customers and `CUSTOM_FD14=0` for new customers in every payment transaction.

= 1.0.6 =
* Keep merchant email out of config sync payloads and use it only for signed request verification.

= 1.0.5 =
* Remove the unnecessary config sync alias endpoint, keep only config pull/update APIs, and restrict remote updates to non-secret business settings.

= 1.0.4 =
* Add signed Beyounger config pull and update REST APIs.

= 1.0.3 =
* Preserve non-whitelisted UTM values in `raw_utm` for returning-customer traffic attribution.

= 1.0.2 =
* Send order traffic context to BeyoungerPay in the transaction `extra` field.

= 1.0.1 =
* Store BeyoungerPay order traffic metadata for returning customers, UTM sources, and external referrers.

= 1.0.0 =
* Initial release.
