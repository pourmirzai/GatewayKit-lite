=== GatewayKit – Payment Gateway for Elementor Forms ===
Contributors: pourmirzai
Tags: elementor, payment, paypal, stripe, mollie
Requires at least: 6.2
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 1.2.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Accept payments through Elementor Pro Forms using PayPal, Stripe, Mollie, and more — without the bloat of WooCommerce or a shopping cart.

== Description ==

**Requirements:** Elementor Pro must be installed and active. The Elementor Forms widget (included with Elementor Pro) is required to create and accept payments through forms.

GatewayKit turns any Elementor Pro Form into a payment form. Build custom forms with your own fields, and customers are redirected to a hosted checkout to complete payment — no shopping cart or WooCommerce required.

If you sell a single product, collect donations, or invoice clients, you don't need a full store — you just need a form that takes payment. GatewayKit is designed for single-product sales, donations, invoices, event registrations, and simple payment flows where WooCommerce would be overkill.

**Payment Gateways Included Free**

* **PayPal** — Modern hosted checkout with sandbox mode and webhook verification
* **Stripe** — Stripe Checkout with automatic 3D Secure and recurring subscriptions
* **Mollie** — iDEAL, credit cards, Bancontact, SOFORT, and more
* **CoinGate** — Bitcoin, Ethereum, and 50+ cryptocurrencies
* **Coinify** — Bitcoin, Ethereum, and popular cryptocurrencies
* **NOWPayments** — Bitcoin, Ethereum, and 100+ altcoins

**Core Features**

* Fixed or dynamic payment amounts (pull price from a form field)
* Transaction dashboard with search, filters, and date-range filtering
* Secure receipt tokens with printable payment result shortcode
* PDF receipts emailed automatically to customers
* PDF invoices downloadable from the receipt page
* Transaction notes for internal order tracking
* Structured logging with sensitive-data redaction
* Per-form failure redirect pages
* Security: input sanitization, output escaping, nonce verification, rate limiting

**GatewayKit Pro (Optional)**

Upgrade to GatewayKit Pro for advanced features:

* Discount (coupon) codes with usage limits, per-user caps, and expiry dates
* Partial (deposit) payments — charge a percentage now, the rest later
* One-click refunds from the dashboard (Stripe + PayPal) — no separate logins
* Analytics dashboard with revenue trends and your best-performing forms
* CSV export for your accountant or CRM (all / selected / filtered)
* Recurring subscriptions powered by Stripe
* Optional (donation-style) payment forms
* Outgoing webhooks to trigger automations in Zapier, Make, and n8n
* White-label mode for agencies and client sites
* Payment Summary field showing customers a clear breakdown before they pay

Visit the [GatewayKit website](https://gatewaykit.pourmirzai.com/) for full documentation and demos.


== Installation ==

1. Install and activate **Elementor** and **Elementor Pro** (the Forms module is required).
2. Upload the `gatewaykit` plugin folder to `/wp-content/plugins/` or install it via Plugins → Add New → Upload Plugin.
3. Activate **GatewayKit** through the Plugins screen.
4. Go to **GatewayKit → Settings** and enable the gateways you want, then enter your credentials.
5. Create an Elementor Pro Form, then under **Actions After Submit** add the **Payment Gateway** action.
6. Configure the gateway, amount, and success/failure redirect pages.
7. Create a result page and add the shortcode `[gatewaykit_receipt]`.

== Frequently Asked Questions ==

= Does this require Elementor Pro? =

Yes. The Elementor Pro Forms module is required because GatewayKit is an "Actions After Submit" handler for Elementor forms.

= Which payment methods are included in Lite? =

All six payment gateways are included in the free plugin: PayPal, Stripe, Mollie, CoinGate, Coinify, and NOWPayments. GatewayKit Pro adds advanced features like discount codes, refunds, analytics, CSV export, and more.

= Are payment credentials stored securely? =

Yes. All API keys and secrets are encrypted at rest (AES-256-CBC) and decrypted only in memory when a payment is processed. Logs automatically redact sensitive data, and the plugin never tracks users or calls home.

= The activation link in my opt-in email returns a 403 error. What should I do? =

This is a known issue documented by Freemius in [Known License Activation Issues → Security Layer Blockage](https://freemius.com/help/documentation/wordpress-sdk/license-activation-issues/). The activation URL contains random security keys with punctuation characters (`%`, `^`, `;`, `)`, `*`, `!`, `>`, `$`) that some security plugins and firewall modules (ModSecurity, Cloudflare WAF, Wordfence, Solid Security, etc.) flag as suspicious and block.

Per Freemius's official guidance, temporarily disable your security plugins/layers/modules just for the activation process:

1. Temporarily disable ModSecurity (cPanel → ModSecurity → Off), Cloudflare WAF (WAF → Off), and/or any WordPress security plugin (Wordfence, Solid Security, etc.).
2. Open the activation email again and click the activation link.
3. Once activation completes, re-enable all the security components you disabled.

The same guidance applies if license-key activation fails for the same reason.

== Screenshots ==

1. GatewayKit dashboard with payment overview.
2. Dashboard widget showing recent payments.
3. Dashboard widget with transaction summary.
4. Settings page with gateway configuration.
5. Gateway settings configured inside Elementor.
6. Selecting a form action to trigger the payment gateway.
7. Transaction management list with filters.
8. Payment receipt generated after a successful transaction.
9. Receipt page when opened directly via transaction query.

== Changelog ==

= 1.2.1 =
* Fixed discount codes not being applied at checkout — discounted total is now correctly sent to all payment gateways

= 1.2.0 =
* Added PDF invoice attachment to customer receipt emails
* Added refund processing from dashboard (Stripe + PayPal)
* Added advanced analytics dashboard with revenue charts (PRO)
* Added invoice PDF download from receipt page
* Added Stripe subscription lifecycle management (PRO)
* Added outgoing webhooks with HMAC-SHA256 verification (PRO)
* Added white-label mode for agencies (PRO)
* Added Payment Summary form field for Elementor (PRO)
* Added optional (donation-style) payment forms (PRO)
* Fixed Payment Summary and Discount Code field rendering in Elementor editor (PRO)

= 1.1.0 =
* Stronger PayPal webhook security with optional signature verification
* Safer payment confirmation for PayPal orders
* Faster payments — PayPal tokens are now cached
* More accurate zero-decimal currency list
* Pro features: CSV export and date-range filter on Transactions page

= 1.0.0 =
* First public release
* PayPal payments through Elementor Pro Forms
* Optional GatewayKit Pro add-on with Stripe, Mollie, and CoinGate gateways

== External Services ==

This plugin connects to payment provider APIs to process payments.

= What is sent and when =

When a user submits a payment form, the plugin sends the payment amount, currency, and a transaction reference to the configured payment provider's servers for authorization and capture. No personal user data beyond what the provider requires for payment processing is transmitted.

= PayPal =

* API Endpoint (Live): https://api-m.paypal.com
* API Endpoint (Sandbox): https://api-m.sandbox.paypal.com
* Terms of Service: https://www.paypal.com/us/legalhub/useragreement-full
* Privacy Policy: https://www.paypal.com/us/legalhub/privacy-full

= Stripe =

* API Endpoint (Live): https://api.stripe.com
* API Endpoint (Test): https://api.stripe.com
* Terms of Service: https://stripe.com/legal
* Privacy Policy: https://stripe.com/privacy

= Mollie =

* API Endpoint (Live): https://api.mollie.com
* API Endpoint (Test): https://api.mollie.com
* Terms of Service: https://www.mollie.com/terms
* Privacy Policy: https://www.mollie.com/privacy

= CoinGate =

* API Endpoint (Live): https://api.coingate.com
* API Endpoint (Test): https://api-sandbox.coingate.com
* Terms of Service: https://coingate.com/terms-of-service
* Privacy Policy: https://coingate.com/privacy-policy

= Coinify =

* API Endpoint (Live): https://api.coinify.com
* API Endpoint (Test): https://api.coinify.com
* Terms of Service: https://coinify.com/terms
* Privacy Policy: https://coinify.com/privacy-policy

= NOWPayments =

* API Endpoint (Live): https://api.nowpayments.io
* API Endpoint (Test): https://api-sandbox.nowpayments.io
* Terms of Service: https://nowpayments.io/terms
* Privacy Policy: https://nowpayments.io/privacy

== Upgrade Notice ==

= 1.2.0 =
Crypto payments via NOWPayments, date filters, customer email receipts, and transaction notes.

= 1.1.0 =
Security and performance release. PayPal webhooks are now verified via PayPal's official signature endpoint — open PayPal settings and paste your Webhook ID to enable full protection. PayPal access tokens are now cached across requests.

= 1.0.0 =
First release. Accept PayPal payments through Elementor Pro Forms. Upgrade to GatewayKit Pro for Stripe, Mollie, and CoinGate.
