# epay-et/php-sdk

Official ePay Business API client for PHP, with a Laravel integration.

Accept payments from every major Ethiopian mobile wallet and bank with one integration.

Full API documentation: <https://docs.epayethiopia.com/>

- **PHP 8.1+**, typed throughout, PSR-4 and PSR-18
- **Automatic retries** with exponential backoff and jitter on `429`/`5xx`/network errors
- **Safe retries on initialize** — every attempt reuses one idempotency key
- **Exact decimal amounts** via bcmath, with a string-comparison fallback
- **Constant-time webhook verification** with `hash_equals`
- **Laravel**: auto-discovered service provider, publishable config, webhook middleware

## Install

```sh
composer require epay-et/php-sdk
```

## Quick start

```php
use Epay\Epay;

$epay = new Epay(['api_key' => getenv('EPAY_SECRET_KEY')]);

$session = $epay->payments->initialize([
    'amount' => '250.00',
    'currencyCode' => 'ETB',
    'customerPhone' => '+251911234567',
    'merchantReference' => 'order_123',
    'returnUrl' => 'https://yourstore.com/orders/123/complete',
], idempotencyKey: 'order_123');

// Save $session['reference'], then send the customer to pay.
header('Location: ' . $session['checkoutUrl']);
```

The key prefix picks the environment: `sk_test_…` runs against the sandbox and
`sk_live_…` moves real money. There is no separate mode to configure.

## Configuration

Every option falls back to an environment variable, so `new Epay()` is enough.

| Option | Environment variable | Default |
| --- | --- | --- |
| `api_key` | `EPAY_SECRET_KEY` | *required* |
| `webhook_secret` | `EPAY_WEBHOOK_SECRET` | — |
| `base_url` | `EPAY_BASE_URL` | `https://api.epayethiopia.com/v1` |
| `timeout` | — | `30.0` seconds per attempt |
| `max_retries` | — | `2` |
| `default_headers` | — | `[]` |

```php
$epay = new Epay(['timeout' => 10.0, 'max_retries' => 3]);

$epay->mode();       // 'live' | 'sandbox' | null
$epay->isSandbox();  // bool
```

`(string) $epay` and `var_dump($epay)` both mask the key, so a client is safe to log.

### Bring your own HTTP client

The second constructor argument takes any PSR-18 client — for connection
pooling, an outbound proxy, or mTLS:

```php
use GuzzleHttp\Client as GuzzleClient;

$http = new GuzzleClient([
    'proxy' => 'http://proxy.internal:8080',
    'http_errors' => false, // let the SDK map statuses and retry
]);

$epay = new Epay(['api_key' => $key], $http);
```

Leave `http_errors` off: the SDK maps non-2xx responses itself so the retry
policy and error hierarchy stay in one place.

## Payments

### Initialize

```php
$session = $epay->payments->initialize([
    'amount' => 250,                  // string, int, or float
    'currencyCode' => 'etb',          // uppercased
    'customerPhone' => '0911234567',  // rewritten to '+251911234567'
    'merchantReference' => 'order_123',
    'email' => 'abebe@example.com',
    'callbackUrl' => 'https://yourstore.com/webhooks/epay', // overrides the dashboard URL
], idempotencyKey: 'order_123');
```

`amount`, `currencyCode`, and `customerPhone` are validated locally, so a typo
throws `EpayValidationException` immediately instead of costing a round trip.

**On amounts.** Pass a string. Integers and floats are accepted and rounded
half-up to two places, but floats cannot represent every decimal amount exactly.
The result always carries two decimal places.

**On idempotency keys.** If you omit `idempotencyKey`, the SDK generates a fresh
one per call. That makes its internal retries safe — a retried `initialize`
cannot double-charge — but it does *not* deduplicate across separate calls. Pass
your own order id to get that guarantee.

### Verify before fulfilling

```php
$receipt = $epay->payments->verify($reference);
// $receipt['status'] === 'completed', plus serviceFee, paymentMethod, customer
```

The API rejects a transaction that is not yet `completed`, so use
`transactions->retrieve` first if you would rather branch on status than catch
an `EpayBadRequestException`.

### Cancel

```php
$epay->payments->cancel($reference); // returns void on 204
```

Only `pending` and `processing` transactions can be cancelled, and cancellation
is irreversible. This call is never retried automatically, because the endpoint
takes no idempotency key.

## Transactions

```php
$transaction = $epay->transactions->retrieve($reference);
$timeline = $epay->transactions->timeline($reference);

foreach ($timeline['events'] as $event) {
    echo $event['eventType'], ' ', $event['occurredAt'], PHP_EOL;
}
```

### Listing and pagination

The endpoint is cursor-paginated at a fixed 10 per page. Iterate a page to walk
every following page, fetching lazily and carrying your filters along:

```php
foreach ($epay->transactions->list([
    'status' => 'completed',
    'currency' => 'ETB',
    'from' => '2026-08-01',
    'to' => '2026-08-31',
]) as $transaction) {
    echo $transaction['reference'], ' ', $transaction['amount'], PHP_EOL;
}
```

`from` and `to` accept a string or any `DateTimeInterface`, and the API caps the
range at 90 days.

Other ways to consume the same endpoint:

```php
$page = $epay->transactions->list();
$page->data();       // exactly this page, no extra requests
$page->hasMore();    // bool
$page->nextPage();   // the next TransactionPage, or null

$recent = $epay->transactions->list()->toArray(50);   // cap the walk

foreach ($epay->transactions->list()->pages() as $page) {  // page at a time
    processBatch($page->data());
}
```

## Payment providers

```php
$epay->paymentProviders->list();         // compact, for dropdowns
$epay->paymentProviders->getAll();       // with category and isEnabled
$epay->paymentProviders->listEnabled();  // only what you can route to today
```

Both endpoints are mode-aware and permission-gated: `list` needs
`list_platform_payment_provider`, `getAll` needs `get_platform_payment_provider`.

## Webhooks

Verify the `X-Epay-Signature` header against the **raw** request body before you
trust a payload. Re-encoding a decoded array can reorder keys and change the
digest, which rejects valid deliveries.

```php
use Epay\Exception\EpayWebhookSignatureException;

$raw = file_get_contents('php://input');
$signature = $_SERVER['HTTP_X_EPAY_SIGNATURE'] ?? null;

try {
    $event = $epay->webhooks->constructEvent($raw, $signature);
} catch (EpayWebhookSignatureException $error) {
    http_response_code(401);
    exit;
}

// Acknowledge fast, process later.
enqueue($event);
http_response_code(200);
```

`constructEvent` fails closed: any event it returns had a valid signature.

### Handling events

```php
match ($event['event']) {
    'payment.success' => fulfil($event['reference']),
    'payment.failed', 'payment.cancelled' => release($event['reference']),
    'payment.refunding', 'payment.refunded', 'payment.reversed' => reconcile($event),
    default => null, // an event this SDK version does not know yet
};
```

ePay retries anything that is not a `2xx` within 10 seconds, up to 5 attempts,
so keep the handler fast and make it idempotent — deduplicate on
`$event['reference']`.

## Laravel

The service provider is auto-discovered. Add your keys to `.env`:

```dotenv
EPAY_SECRET_KEY=sk_test_your_key_here
EPAY_WEBHOOK_SECRET=your_webhook_secret
```

Then inject the client anywhere:

```php
use Epay\Epay;

final class CheckoutController
{
    public function __construct(private readonly Epay $epay) {}

    public function store(Request $request)
    {
        $session = $this->epay->payments->initialize([
            'amount' => $request->string('amount')->value(),
            'currencyCode' => 'ETB',
            'customerPhone' => $request->string('phone')->value(),
            'merchantReference' => $order->id,
        ], idempotencyKey: $order->id);

        return redirect()->away($session['checkoutUrl']);
    }
}
```

Publish the config to change defaults:

```sh
php artisan vendor:publish --tag=epay-config
```

### Webhook middleware

ePay sends no CSRF token, so exclude the route and alias the middleware. In
`bootstrap/app.php` (Laravel 11+):

```php
use Epay\Laravel\VerifyEpayWebhook;

->withMiddleware(function (Middleware $middleware) {
    $middleware->validateCsrfTokens(except: ['webhooks/epay']);
    $middleware->alias(['epay.webhook' => VerifyEpayWebhook::class]);
})
```

On Laravel 10, add `'webhooks/epay'` to `$except` in
`app/Http/Middleware/VerifyCsrfToken.php` and register the alias in
`app/Http/Kernel.php`.

Then the route:

```php
use Epay\Laravel\VerifyEpayWebhook;

Route::post('/webhooks/epay', function (Request $request) {
    $event = VerifyEpayWebhook::event($request);

    ProcessEpayEvent::dispatch($event); // acknowledge fast, process later

    return response()->noContent(200);
})->middleware('epay.webhook');
```

The middleware rejects a bad or missing signature with `401` before your route
runs, and `VerifyEpayWebhook::event()` throws rather than returning an
unverified payload if the middleware was not applied.

## Error handling

Every failure extends `EpayException`. HTTP failures carry the status, parsed
body, and response headers.

```php
use Epay\Exception\EpayApiException;
use Epay\Exception\EpayBadRequestException;
use Epay\Exception\EpayNotFoundException;
use Epay\Exception\EpayRateLimitException;

try {
    $receipt = $epay->payments->verify($reference);
} catch (EpayNotFoundException) {
    return null;
} catch (EpayBadRequestException) {
    return 'not settled yet';
} catch (EpayRateLimitException $error) {
    sleep((int) ($error->retryAfterSeconds() ?? 1));
    throw $error;
} catch (EpayApiException $error) {
    Log::error('ePay failure', [
        'status' => $error->status,
        'request' => $error->request,
        'body' => $error->body,
    ]);
    throw $error;
}
```

| Class | Thrown when |
| --- | --- |
| `EpayValidationException` | A value failed local validation; no request was sent |
| `EpayConfigException` | The client was constructed with unusable options |
| `EpayBadRequestException` | `400` |
| `EpayAuthenticationException` | `401` — key missing, invalid, or revoked |
| `EpayPermissionDeniedException` | `403` — IP not whitelisted, or key lacks a permission |
| `EpayNotFoundException` | `404` |
| `EpayConflictException` | `409` |
| `EpayRateLimitException` | `429` — exposes `retryAfterSeconds()` |
| `EpayServerException` | `5xx` |
| `EpayTimeoutException` | The attempt exceeded `timeout` |
| `EpayConnectionException` | No response was received at all |
| `EpayWebhookSignatureException` | A webhook signature was missing or wrong |

`429`, `5xx`, and network errors are retried automatically before surfacing.

## Sandbox testing

Use a `sk_test_…` key with the documented magic phone numbers:

| Phone | OTP | Outcome |
| --- | --- | --- |
| `251900000000` | `000111` | Generic sandbox account |
| `251900000001` | `123456` | Completes, fires `payment.success` |
| `251900000002` | `654321` | `INVALID_OTP` |
| `251900000003` | `111111` | `OTP_EXPIRED` |
| `251900000004` | — | Declined, fires `payment.failed` |

Generate a fresh idempotency key per test run: reusing one returns the cached
response instead of triggering the scenario again.

### Testing your own code

Pass a scripted PSR-18 client and no request leaves the process — see
`tests/ClientTest.php` for a ready-made `ScriptedHttpClient`.

## Unmodelled endpoints

`$epay->request()` reaches anything this version does not wrap yet, with the same
auth, timeout, retry, and error handling:

```php
$data = $epay->request('GET', '/some/new/endpoint', ['limit' => 10]);
```

## Development

```sh
composer install
composer test          # phpunit
composer analyse       # phpstan, level 8
composer format        # php-cs-fixer
composer format:check  # php-cs-fixer, dry run with a diff
```

## Releasing

CI runs the suite on PHP 8.1 through 8.4 — including a `lowest` dependency
resolution on 8.1, so the declared minimum constraints are proven to work, not
just the newest releases — plus PHPStan level 8, php-cs-fixer, and
`composer validate --strict`.

One-time setup:

1. Submit the repository at
   [packagist.org/packages/submit](https://packagist.org/packages/submit).
2. Enable the Packagist GitHub integration so new tags publish automatically
   (Packagist → your profile → Settings, or the repo's webhook settings).

Optional fallback, only if you do not set up that integration: add the
repository secrets `PACKAGIST_USERNAME` and `PACKAGIST_TOKEN`. The release
workflow then pings the Packagist API itself, and skips that step when the
secrets are absent.

To release:

```sh
git tag v0.2.0 && git push origin v0.2.0
```

Packagist derives the version from the tag — there is no build step and nothing
to upload. `release.yml` therefore runs the full check suite on the tag as a
gate, because a published version cannot be withdrawn.

Note that Composer reads `composer.json` from the **repository root**, which is
why this SDK lives in its own repository rather than a subdirectory.

## License

MIT
