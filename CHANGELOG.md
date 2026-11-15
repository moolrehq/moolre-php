# Changelog

All notable changes to `moolre/moolre-php` will be documented in this file.

## Unreleased

## 1.1.0 - 2026-11-15

- Add the server SDK `Moolre\Moolre`: collections with the OTP flow, payouts with optional `senderName`, name validation, internal transfers, accounts and cursor-paginated transactions, airtime and data sales, SMS, WhatsApp, payment links, bulk payouts, invoices and webhook deliveries/resend/test.
- Add `Moolre\Webhook` (`verify`, `construct`, `parse`, `sign`) for `X-Moolre-Signature` verification.
- Results expose `requestId` and `notices`; money calls accept an `idempotencyKey`; optional strict HTTP status mode.
- Add `scripts/bundle.php` to build a single-file `moolre-sdk.php`.
- `Moolre\Client` and all existing classes are unchanged.

## 1.0.2 - 2026-11-11

- Reject ambiguous verification responses with conflicting status, reference, or account-number fields.
- Bind successful verification responses to the client's configured account number.
- Add `verifySuccessfulPayment()` for strict reference, status, amount, and account verification.
- Require HTTPS callback and redirect URLs except for loopback development URLs.
- Reject floating-point amounts with more than two decimal places.
- Generate references with 128 bits of secure randomness and fail closed if secure randomness is unavailable.
- Harden the raw PHP example with CSRF protection, session-authorized receipts, local-only redacted order listings, bounded callback bodies, safe errors, and atomic idempotent payment transitions.
- Correct security and framework-integration guidance.

## 1.0.1 - 2026-07-23

- Made transaction-status responses require an `externalref` or equivalent reference that exactly matches the requested payment reference.
- Documented `matchesAmountAndAccountNumber()` as the verification helper for the Moolre transaction-status endpoint.
- Deprecated `matchesPaymentDetails()` for Moolre transaction-status responses because that endpoint does not return currency or customer email.
- Added regression coverage for the Moolre transaction-status response schema.

## 1.0.0 - 2026-07-17

- Added framework-agnostic Moolre payment initiation and verification client.
- Added native cURL/PHP stream transport.
- Added raw PHP, Laravel, Symfony, Slim, and CodeIgniter documentation examples.
- Added complete raw PHP example app.
- Added support for payment `redirect`, `expiration_time`, and `metadata` initiation fields.
- Added sandbox endpoint support with required `X-API-USER` header and optional sandbox public key.
- Updated callback documentation to distinguish server webhook callbacks from customer redirects.
- Hardened base URL, callback URL, header, and verification reference validation.
- First public package release.
