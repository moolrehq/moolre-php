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

- Always verify a payment server-side with `verifyPayment()` before delivering value.
- Always compare local order amount/currency/customer data with your own database records.
- Use `matchesPaymentDetails()` to require matching amount, currency, customer email, and account number before marking an order paid.
- Never trust callback/webhook payloads or redirect query parameters without verification.
- Use the callback URL for server-to-server payment updates and the redirect URL for the customer-facing return page.
- In sandbox, keep the required `X-API-USER` value private just like other account credentials.
- Keep Moolre credentials out of source control.
