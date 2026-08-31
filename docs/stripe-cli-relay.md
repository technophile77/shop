# CLI Relay

Why `StripeService`'s checkout methods, `TwilioService::send()`/`sendBulk()`,
and `QuoteService::notifyOwner()` don't make their outbound HTTPS call
directly when invoked from a web request — they shell out via
`App\Support\CliRelay` to `bin/cli-relay.php` instead.

This document is written for whoever has to debug this in six months and has
forgotten why it's here.

## 1. The bug this works around

Discovered 2026-08-19, diagnosing a customer's card-payment link returning
"Connection error. Please try again." — the client-side fetch-failure fallback
in `views/public/quote-accept.php`'s `payByCard()`, which fires when the HTTP
response never completes or its body isn't valid JSON.

Root cause: on this host (pair.com), PHP's cURL extension has **no SSL/TLS
backend at all** under the Apache SAPI:

```
curl_version()['ssl_version']
  apache2handler:  "OpenSSL/not available"
  cli:              "OpenSSL/3.0.20"
```

Same libcurl build (8.21.0), same box — just a different SAPI. Every outbound
HTTPS call made via `curl_exec()` — which is how the Stripe SDK, Twilio SMS,
and every other integration in this codebase talk to their APIs — severs the
connection to the *browser* mid-response when run under Apache, instead of
failing gracefully. With `APP_DEBUG=false` (`error_reporting(0)`,
`display_errors=0`), that produces a genuinely empty HTTP response — no
status line the client can act on, hence the fetch-level "Connection error."

This is a variant of the TLS problem already documented for outbound SMTP on
this host (see `server-smtp-tls-apache` project memory): Apache-embedded PHP's
TLS stack is broken here in more than one way. That one was catchable
(`stream_socket_enable_crypto()` returning false); this one isn't — it takes
the whole request down, with no PHP-level exception to catch.

Confirmed via a step-by-step flushing script under the web SAPI: execution
reaches the exact line that calls `checkout->sessions->create()` and dies
there, every time, in well under 100ms (too fast to be a timeout). The
identical call under CLI PHP (`php82`) succeeds immediately and creates a
real Stripe Checkout Session.

**This one bug silently broke two unrelated-looking things**, discovered a
few hours apart in the same investigation:

1. The card-payment link itself (Stripe's `checkout->sessions->create()` /
   `->retrieve()` calls).
2. The owner SMS notification on a confirmed quote payment
   (`QuoteService::notifyOwner()`, which calls Twilio directly with its own
   `curl_exec()`) — quote #28 was paid successfully (the DB write happens
   *before* the SMS call in `StripeController::handleQuoteSuccess()`), but
   the crash killed the request right after, so the owner-payment **email**
   that runs *after* the SMS call in that same method never sent either, and
   nothing was logged (a connection-level crash isn't a catchable exception).
   Any code that makes an outbound cURL call from a web-reachable path on
   this host is a candidate for the same failure — see §4.

## 2. The workaround

`App\Support\CliRelay::run($class, $method, $args)` shells out via
`proc_open()` to a CLI subprocess that makes the real call, where cURL's SSL
backend works:

```
Apache request → StripeService::createQuoteCheckoutSession(...)
              → CliRelay::isNeeded() → CliRelay::run(self::class, 'createQuoteCheckoutSession', $args)
              → proc_open(['php82', 'bin/cli-relay.php'])
                  → CLI subprocess: PHP_SAPI === 'cli' → calls the same method again,
                    which now runs its real body instead of relaying (no recursion)
                  → writes {"ok": true, "result": {...}} to stdout
              → parent decodes stdout, returns the result (or reconstructs
                the original Stripe exception on failure)
```

`bin/cli-relay.php` is CLI-only-guarded the same way `bin/stripe-reconcile.php`
and `bin/sales-report.php` are — the docroot is the project root, so any
`.php` file under it is directly web-reachable unless it refuses to run
outside CLI. It only dispatches to an explicit allowlist of `class => [methods]`
inside the script itself.

Each caller decides how to surface a relay-level failure (the subprocess
couldn't start, timed out, or crashed), matching its own existing error
contract:

- `StripeService`'s methods let it propagate (`\Stripe\Exception\ApiErrorException`
  is reconstructed from the subprocess's JSON when Stripe itself rejected the
  request; anything else raises `\RuntimeException`, same as before this
  existed — callers already catch `\Throwable`).
- `TwilioService::send()`/`sendBulk()` catch it and return their normal
  failure-array shape (`['success' => false, ...]`), since those methods
  never throw.
- `QuoteService::notifyOwner()` catches it, logs, and returns — it's `void`
  and already documented as "a failed SMS never breaks the customer-facing
  flow."

`StripeService::retrieveCheckoutSession()`'s return type is `object`, not the
SDK's `\Stripe\Checkout\Session` — relayed calls come back as a JSON
round-tripped `\stdClass` with the same field names, since the real SDK
object can't cross a subprocess boundary (`CliRelay::run(..., resultIsObject: true)`
is what selects object-mode decoding). Every caller in this codebase only
ever does property access (`$session->payment_status`,
`$session->metadata->quote_token`, …), which works identically either way.

## 3. A second, related finding: web-request logging was silently broken

While diagnosing this, we found Apache's PHP on this host runs as
`uid=nobody gid=www` (confirmed via `posix_geteuid()`) — a completely
different identity from CLI, which runs as the account owner. Two
consequences:

1. No cURL SSL backend under that identity (§1 above).
2. No write access to `acedeath`-owned files, including `logs/php_errors.log`
   — meaning every `error_log()` call made from a *web* request (as opposed
   to a CLI/cron script) had been silently going nowhere. `logs/` and
   `logs/php_errors.log` were `chmod o+w`'d to fix this (direct web access to
   `logs/*.log` is already blocked — confirmed 403 — so this doesn't add a
   read-exposure). If a future controller's error logging seems to vanish,
   check this first.

See the `server-apache-nobody-user` project memory for the full writeup.

## 4. If this ever needs revisiting

- **Real fix**: this is a pair.com hosting-environment defect, not an app
  bug — worth a support ticket asking why `apache2handler` PHP's cURL has no
  SSL backend while CLI PHP's does. If pair.com fixes it, every
  `CliRelay::isNeeded()` check can simply be deleted, along with
  `CliRelay`, `bin/cli-relay.php`, and this document.
- **`PHP_CLI_BINARY`** (`.env`) points at the CLI php binary; defaults to
  `/usr/local/bin/php82` if unset.
- **Timeout**: the relay subprocess is killed after 25s
  (`CliRelay::run()`); a hung subprocess surfaces as a `\RuntimeException`.
- **Other cURL integrations that are NOT yet relayed, and ARE web-reachable**
  — `InstagramService` (`Admin\AnalyticsController`), `FacebookAdsService`
  (`Admin\CampaignsController` — campaign create/pause, image upload,
  insights), and `GoogleMerchantService::upsert()`/`delete()`
  (`Admin\ProductsController`, on every product save) are all called
  directly from admin-panel web requests, with no relay. They are just as
  exposed to this bug as Stripe and Twilio were — confirmed present, **not**
  yet fixed as of this writing. `GoogleAuth` backs `GoogleMerchantService`'s
  OAuth token refresh, same exposure. `bin/merchant-sync.php` is a *separate*
  CLI-only path for bulk sync and is unaffected either way.
- If a future method needs to make an outbound HTTPS call from a web
  request, add its class/method to the allowlist in `bin/cli-relay.php` and
  give it the same `CliRelay::isNeeded()` guard, choosing the failure
  contract that matches its existing callers (throw vs. return-array vs.
  log-and-return — see §2).
