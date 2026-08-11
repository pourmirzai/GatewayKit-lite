# GatewayKit Changelog

## 1.3.1

**Critical fixes**

- Bulk "Export" on the Transactions page now downloads the selected rows instead of failing silently
- Webhooks for Razorpay, Paystack, Mercado Pago, and NOWPayments now correctly process pending payments
- White-label magic link unlocks the settings page again

**Security hardening**

- Payment redirect pages are now safe to reload — duplicate receipts and redundant notifications are prevented
- PayPal and Coinify webhooks require signing secrets to be configured; unverified requests are rejected
- Removed the request scanner that was blocking legitimate form fields; SQL safety is still enforced at the database layer
- Duplicate rate-limit implementations consolidated into one consistent system
- Encrypted gateway secrets now refuse to operate when WordPress security keys are absent, with a clear admin notice

**Reliability**

- Outgoing webhooks are now delivered asynchronously — a slow downstream service no longer stalls the buyer's success page
- Proxy-spoofed IP headers can no longer bypass per-IP rate limits (unless you explicitly configure trusted proxy ranges)
- The payment endpoint now validates redirect URLs and can't be replayed across clients

## 1.3.0

**New gateways and payment methods**

* Added Razorpay — UPI, cards, net banking, and wallets for Indian merchants
* Added Paystack — cards, bank transfers, and mobile money for African markets
* Added Mercado Pago — cards, PIX, and local methods for Latin American markets
* Added Apple Pay and Google Pay support via Stripe Checkout
* Added Klarna and Afterpay / Clearpay (Buy Now Pay Later) via Stripe Checkout
* Added Stripe Embedded Checkout — keep customers on your site with no redirect
* Added Payment Links — generate shareable Stripe checkout links without a form

## 1.2.1

**Hotfix**

- Fixed discount codes not being applied at checkout — the discounted total is now correctly sent to all payment gateways

## 1.2.0

**Advanced Pro features + Lite enhancements**

**Lite Features:**
- All six payment gateways included: PayPal, Stripe, Mollie, CoinGate, Coinify, NOWPayments
- PDF invoices emailed with receipt attachments
- PDF invoice downloads from receipt page
- Transaction notes for internal tracking
- Date-range filtering on Transactions page

**Pro Features:**
- Discount codes with usage limits and date validity
- Refund processing from dashboard (Stripe + PayPal)
- Advanced analytics dashboard with revenue charts
- CSV export (all / selected / filtered)
- Optional (donation-style) payment forms
- Outgoing webhooks with HMAC-SHA256 verification
- White-label mode for agencies
- Payment Summary form field for Elementor
- Stripe subscription lifecycle management

**Fixes:**
- Fixed Payment Summary and Discount Code field rendering in Elementor editor
- Fixed multi-form payment settings scoping issues
- Improved PDF invoice generation with proper error handling
- Fixed empty currency setting causing Stripe 'Invalid currency' error; all gateways now fallback to default currency

## 1.1.0

**PayPal security and performance improvements**

- Stronger PayPal webhook security with optional signature verification
- Safer payment confirmation for PayPal orders
- Faster payments — PayPal access tokens are now cached
- More accurate zero-decimal currency list
- Pro features: CSV export and date-range filter on Transactions page

## 1.0.0

**Initial public release**

- PayPal payments through Elementor Pro Forms
- Optional GatewayKit Pro add-on with Stripe, Mollie, and CoinGate gateways
- License-based feature unlocking with built-in activation and updates flow