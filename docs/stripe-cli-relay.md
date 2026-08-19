# Stripe CLI Relay

Why `StripeService::createQuoteCheckoutSession()`, `createCartCheckoutSession()`,
and `retrieveCheckoutSession()` don't call the Stripe SDK directly when invoked
from a web request — they shell out to `bin/stripe-cli-relay.php` instead.

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
HTTPS call the Stripe PHP SDK makes (`curl_exec()` under the hood) severs the
connection to the *browser* mid-response when run under Apache, instead of
failing gracefully. With `APP_DEBUG=false` (`error_reporting(0)`,
`display_errors=0`), that produces a genuinely empty HTTP response — no
status line the client can act on, hence the fetch-level "Connection error."

This is a variant of the TLS problem already documented for outbound SMTP on
this host (see `server-smtp-tls-apache` project memory): Apache-embedded PHP's
TLS stack is broken here in more than one way. That one was catchable
(`stream_socket_enable_crypto()` returning false); this one isn't — it takes
the whole request down.

Confirmed via a step-by-step flushing script under the web SAPI: execution
reaches the exact line that calls `checkout->sessions->create()` and dies
there, every time, in well under 100ms (too fast to be a timeout). The
identical call under CLI PHP (`php82`) succeeds immediately and creates a
real Stripe Checkout Session.

## 2. The workaround

Any `StripeService` method that makes a network call to Stripe checks
`PHP_SAPI` and, when not `'cli'`, relays the call to a CLI subprocess via
`proc_open()` (`StripeService::runViaCliRelay()`) instead of running it
in-process:

```
Apache request → StripeService::createQuoteCheckoutSession(...)
              → PHP_SAPI !== 'cli' → runViaCliRelay(...)
              → proc_open(['php82', 'bin/stripe-cli-relay.php'])
                  → CLI subprocess: PHP_SAPI === 'cli' → runs the real body
                  → writes {"ok": true, "result": {...}} to stdout
              → parent decodes stdout, returns the result (or reconstructs
                the original Stripe exception on failure)
```

The relay script (`bin/stripe-cli-relay.php`) is CLI-only-guarded the same
way `bin/stripe-reconcile.php` and `bin/sales-report.php` are — the docroot
is the project root, so any `.php` file under it is directly web-reachable
unless it refuses to run outside CLI.

`retrieveCheckoutSession()`'s return type is `object`, not the SDK's
`\Stripe\Checkout\Session` — relayed calls come back as a JSON round-tripped
`\stdClass` with the same field names, since the real SDK object can't cross
a subprocess boundary. Every caller in this codebase only ever does property
access (`$session->payment_status`, `$session->metadata->quote_token`, …),
which works identically either way.

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
  SSL backend while CLI PHP's does. If pair.com fixes it, the `PHP_SAPI`
  checks in `StripeService` can simply be deleted along with
  `bin/stripe-cli-relay.php` and this document.
- **`PHP_CLI_BINARY`** (`.env`) points at the CLI php binary; defaults to
  `/usr/local/bin/php82` if unset.
- **Timeout**: the relay subprocess is killed after 25s
  (`runViaCliRelay()`); a hung subprocess surfaces as a `\RuntimeException`,
  caught by the controllers same as any other Stripe failure.
- If a future `StripeService` method needs to make an outbound Stripe call
  from a web request, add it to the allowlist in `bin/stripe-cli-relay.php`
  and give it the same `PHP_SAPI !== 'cli'` guard.
