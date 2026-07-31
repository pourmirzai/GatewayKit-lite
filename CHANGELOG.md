# GatewayKit Changelog

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