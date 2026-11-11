# Security Policy

## Supported Versions

Security fixes are planned for the latest released minor version of `moolre/moolre-php`.

## Reporting a Vulnerability

Please report suspected security issues privately by emailing `hello@moolre.com`.

Do not open public issues for vulnerabilities involving credentials, payment verification, callback handling, or transaction data.

When reporting, include:

- Package version
- PHP version
- Affected method or file
- Steps to reproduce
- Expected and actual behavior

## Payment Safety Notes

- Always verify a payment server-side with `verifySuccessfulPayment()` before delivering value.
- Load only a pending local order by its exact, opaque Moolre reference.
- Use `verifySuccessfulPayment($reference, $localOrderAmount)` before marking an order paid. It requires a successful status and exact reference, amount, and configured account-number matches.
- The transaction-status endpoint does not return currency or customer email. Validate those values when creating the local order, but do not claim that the status response confirms them.
- Never trust callback/webhook payloads or redirect query parameters without verification.
- Use the callback URL for server-to-server payment updates and the redirect URL for the customer-facing return page.
- Make payment updates idempotent and atomic. Only transition an allowed unpaid state to paid, and never reopen cancelled or refunded orders from a callback replay.
- Authorize customer-facing receipt pages with the customer's session or a separate signed, one-time state value. A payment reference alone is not permission to view order data.
- In sandbox, keep the required `X-API-USER` value private just like other account credentials.
- Keep Moolre credentials out of source control.
