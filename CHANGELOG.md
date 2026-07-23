# Changelog

All notable changes to `moolre/moolre-php` will be documented in this file.

## Unreleased

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
