=== K-Pay for WooCommerce ===
Contributors: steveboussa
Tags: woocommerce, payment gateway, mobile money, mtn momo, orange money
Requires at least: 6.0
Tested up to: 7.0
Requires PHP: 7.4
WC requires at least: 7.0
WC tested up to: 10.9
Stable tag: 2.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Accept Mobile Money payments (MTN MoMo, Orange Money, Airtel, M-Pesa) in WooCommerce across 12 African countries.

== Description ==

K-Pay adds Mobile Money payments to your WooCommerce store. The plugin handles
collection only: balances and payouts are managed from your K-Pay dashboard
(https://admin.kpay.site).

= Two payment modes =

In **USSD** mode, the customer picks an operator and enters their phone number
at checkout, then receives a confirmation request on their phone.

In **Hosted gateway** mode, the customer is redirected to the K-Pay payment
page, then returned to your store through a signed URL whose signature the
plugin verifies before reconfirming the status with the API.

The mode selected in the plugin must match the one configured for your
Application in the K-Pay dashboard, otherwise the API rejects payments.

In both modes, an order is only marked as paid after a verified confirmation
from K-Pay, never on the basis of a redirect.

= Language =

Plugin text follows the WordPress language by default. You can force it to
French or English without affecting the rest of the site.

= Supported operators =

* Benin — MTN MoMo, Moov
* Cameroon — MTN MoMo, Orange Money
* Congo — Airtel, MTN MoMo
* Côte d'Ivoire — MTN MoMo, Orange Money
* DR Congo — Vodacom M-Pesa, Airtel, Orange
* Gabon — Airtel Money
* Kenya — M-Pesa
* Rwanda — Airtel, MTN MoMo
* Senegal — Free Money, Orange Money
* Sierra Leone — Orange Money
* Uganda — Airtel, MTN MoMo
* Zambia — Airtel, MTN MoMo, Zamtel

= Currencies =

XAF, XOF, KES, CDF, UGX, RWF, ZMW, SLE. No conversion is performed: the store
currency must match the currency of the operator's country.

= Security =

* Signed webhooks (HMAC-SHA256, constant-time comparison, replay window)
* Mandatory timestamp and deduplication of replayed notifications
* Webhook amount compared against the order total
* Only payment.* events can change an order status
* Hosted gateway return is signed, then reconfirmed through an API call
* Amounts always computed server-side
* API keys never exposed to the browser and never logged
* Test and production environments strictly separated

= External service =

This plugin relies on K-Pay, a third-party Mobile Money payment service, to
process payments. Without this service the plugin cannot work: K-Pay is what
contacts the customer's mobile operator and confirms the payment.

Data is exchanged with https://admin.kpay.site at these points:

* On checkout, the plugin sends the amount, currency, order reference, selected
  operator and the customer's Mobile Money phone number, in order to start the
  payment request.
* While waiting, the plugin queries the service with the transaction identifier
  to retrieve its status.
* In hosted gateway mode, the customer is redirected to the K-Pay payment page,
  then returned to the store.
* The service notifies the store through a signed webhook when the payment
  succeeds or fails.

No data is sent before the customer selects K-Pay as the payment method and
places the order.

Service provided by K-Pay:

* Website: https://kpay.site
* Dashboard: https://admin.kpay.site
* Terms of use: https://kpay.site/legal/conditions
* Privacy policy: https://kpay.site/legal/confidentialite
* Legal notice: https://kpay.site/legal/mentions

== Installation ==

1. Upload the `wc-kpay-gateway` folder to `/wp-content/plugins/`, or install the
   plugin from Plugins → Add New.
2. Activate the plugin through the Plugins menu.
3. Go to WooCommerce → Settings → Payments → K-Pay (Mobile Money).
4. Enter your API keys, select your operators, and configure the webhook URL in
   your K-Pay dashboard.
5. Set the payment mode to the one configured for your Application in the K-Pay
   dashboard. In hosted gateway mode, also enter the gateway secret.
6. Test in Sandbox before switching to production.

WooCommerce must be installed and active.

== Frequently Asked Questions ==

= K-Pay does not appear at checkout =

Check that the "Enable" box is ticked, that the keys for the current
environment are filled in, and that your store currency matches an enabled
operator. The settings screen shows the exact reason.

= My store is in euros, can I use K-Pay? =

No. K-Pay collects in the currency of the operator's country and does not
convert currencies. Set your store to XAF, XOF, KES, CDF, UGX, RWF, ZMW or SLE.

= How do I test without real money? =

Select the Sandbox environment and use your `kpay_test_` keys. In sandbox, the
phone number determines the outcome: `237653456789` succeeds, `237653456029`
fails.

= Orders stay pending =

The webhook is not reaching your site. This is expected on a local install:
K-Pay cannot reach `localhost`. In production, check the webhook URL and secret
configured in your K-Pay dashboard.

= My payments are rejected with a 400 error =

The plugin payment mode does not match the one configured for your Application
in the K-Pay dashboard. The K-Pay configuration is authoritative: align the
plugin setting with your Application.

= What is the difference between the webhook secret and the gateway secret? =

The webhook secret verifies the signature of notifications K-Pay sends to your
site. The gateway secret, used only in hosted gateway mode, verifies the
signature of the URL the customer is returned through. They are distinct and
not interchangeable. Without a gateway secret, hosted gateway mode refuses to
start a payment.

= Where do I check balances and make payouts? =

From your K-Pay dashboard at https://admin.kpay.site. The plugin only handles
collection.

= Is the plugin compatible with the block checkout? =

Yes, as well as with the classic checkout and HPOS order storage.

== Changelog ==

= 2.1.0 =
* New "Hosted gateway" mode: the customer pays on the K-Pay page, and the
  return is authenticated by signature then reconfirmed with the API
* New "Language" setting: French, English, or the site language
* New "Gateway secret" setting, distinct from the webhook secret
* Security: the confirmed amount is compared against the order total; a
  confirmation for an insufficient amount no longer completes the order
* Security: only `payment.*` events drive order status (refunds and payouts can
  no longer mark an order as paid)
* Security: timestamps are now mandatory on notifications, and replayed
  notifications are deduplicated
* Security: status checks are rate-limited, and responses are uniform so they
  do not reveal whether an order exists
* Balances and payouts are now managed from the K-Pay dashboard; the
  corresponding menu was removed from WordPress

= 2.0.0 =
* Complete rewrite, compliant with K-Pay API v1
* K-Pay menu: balances per currency and Mobile Money payouts
* Support for the block checkout and HPOS
* Signed webhooks with HMAC verification and a replay window
* Sandbox/Live switch with key prefix validation
* Strict separation of test and production data
* 12 countries and 23 operators supported

== Upgrade Notice ==

= 2.1.0 =
The "Payment mode" setting must match the mode configured for your Application
in the K-Pay dashboard, otherwise payments are rejected. In hosted gateway
mode, also set the gateway secret.

= 2.0.0 =
First public release. Set the webhook secret in the settings: without it,
payment notifications are rejected.
