# Moolre Raw PHP Example

Small, framework-free PHP app showing how to initiate a Moolre payment, handle the webhook callback, and verify the customer redirect with `moolre/moolre-php`.

The example intentionally uses plain PHP files and a JSON order store so the SDK flow is easy to follow:

- `public/index.php`: displays a checkout form
- `public/pay.php`: creates a local pending order, initiates Moolre payment, and redirects to the authorization URL
- `public/callback.php`: receives Moolre's server-to-server callback/webhook POST, verifies the reference, and updates the local order
- `public/redirect.php`: receives the customer after checkout, verifies the reference, and renders the payment result
- `public/orders.php`: lists recent local demo orders

The order list is available only from a loopback address and redacts email addresses and references. The customer receipt is bound to the browser session that initiated checkout. These safeguards are examples, not a replacement for your application's authentication and authorization rules.

## Run the Example

From the SDK repository:

```bash
cd examples/raw-php-app
composer install
```

Set your Moolre credentials and local app URL.

Option 1: create a local `.env` file:

```bash
cp .env.example .env
```

On Windows PowerShell:

```powershell
Copy-Item .env.example .env
```

Then edit `.env` with your real Moolre values.

Option 2: set environment variables in the same terminal where you will run the PHP server.

PowerShell:

```powershell
$env:MOOLRE_PRODUCTION="false"
$env:MOOLRE_ACCOUNT_NUMBER="your_account_number"
$env:MOOLRE_API_USER="your_moolre_account_username"
$env:MOOLRE_PUBLIC_KEY=""
$env:MOOLRE_EXPIRATION_TIME="10"
$env:APP_URL="http://localhost:8080"
```

macOS/Linux:

```bash
export MOOLRE_PRODUCTION="false"
export MOOLRE_ACCOUNT_NUMBER="your_account_number"
export MOOLRE_API_USER="your_moolre_account_username"
export MOOLRE_PUBLIC_KEY=""
export MOOLRE_EXPIRATION_TIME="10"
export APP_URL="http://localhost:8080"
```

Start PHP's built-in server:

```bash
php -S localhost:8080 -t public
```

Open `http://localhost:8080` in your browser.

## Live and Sandbox Mode

Use `MOOLRE_PRODUCTION` to switch modes:

- `MOOLRE_PRODUCTION=true`: live mode, using the live Moolre endpoint
- `MOOLRE_PRODUCTION=false` or unset: sandbox mode, using `https://sandbox.moolre.com/embed/src/start`

Live mode requires `MOOLRE_PUBLIC_KEY`, `MOOLRE_ACCOUNT_NUMBER`, and `MOOLRE_API_USER`.

Sandbox mode requires `MOOLRE_ACCOUNT_NUMBER` and `MOOLRE_API_USER`. The SDK sends `MOOLRE_API_USER` as the `X-API-USER` header. `MOOLRE_PUBLIC_KEY` is optional for sandbox payment initiation.

The example verifies payments with `https://api.moolre.com/open/transact/status` in live mode. It uses the same account username for every verification request. If your sandbox status endpoint differs from the default `https://sandbox.moolre.com/open/transact/status`, set `MOOLRE_VERIFICATION_URL` explicitly.

Moolre’s status response provides transaction status, the original external reference, amount, and account number, but not currency or customer email. The example therefore marks an order paid only when Moolre reports a successful status and those returned reference, amount, and account values match the pending local order.

`MOOLRE_BASE_URL` and `MOOLRE_VERIFICATION_URL` are available as advanced overrides, but normal switching should happen through `MOOLRE_PRODUCTION`.

## Callback and Redirect URLs

The app sends two URLs to Moolre:

```text
callback: http://localhost:8080/callback.php
redirect: http://localhost:8080/redirect.php
```

`callback` is the server/webhook URL Moolre posts payment updates to. `redirect` is where the customer's browser returns after hosted checkout. The example also sends optional `expiration_time` and `metadata` fields; `expiration_time` must be at least `1` minute when set.

If Moolre cannot reach your local machine directly for callback/webhook delivery, use a tunnel such as ngrok or Cloudflare Tunnel and set `APP_URL` to the public tunnel URL before starting the server.

## SSL Certificate Error on Windows

If you see this error:

```text
SSL certificate problem: unable to get local issuer certificate
```

your local PHP/cURL install cannot find a trusted CA certificate bundle. WooCommerce can still work in the same machine because WordPress uses its own HTTP layer and CA handling.

Fix it by downloading `cacert.pem` from the official cURL CA bundle page and pointing PHP to it.

Option 1: set it in `.env`:

```env
MOOLRE_CA_FILE=C:\path\to\cacert.pem
```

Option 2: set it in your `php.ini`:

```ini
curl.cainfo="C:\path\to\cacert.pem"
openssl.cafile="C:\path\to\cacert.pem"
```

Restart the PHP server after changing either setting.

## Production Notes

This example keeps the code deliberately small. In a real app:

- Store orders in your database instead of `storage/orders.json`
- Validate the order amount from your own database, not from user input
- Require a signed-in user or trusted session before initiating payment
- Optionally set `expiration_time` to a short window that makes sense for your checkout flow. The minimum is `1` minute.
- Verify the Moolre reference on your server before marking anything as paid
- Reject callback payloads and redirect requests whose reference does not match a local pending order
- Use an atomic, idempotent pending-to-paid transition and uniquely store the provider transaction ID
- Never reopen cancelled or refunded orders from callback replays
- Rate-limit public callback and checkout routes at your web server or application edge
- Keep receipt and order-list pages behind customer or administrator authorization
- Log SDK exceptions internally and show safe error messages to customers
