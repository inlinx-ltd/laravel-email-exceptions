# Laravel Email Exceptions

> **Maintained fork** of [`abrigham/laravel-email-exceptions`](https://github.com/abrigham1/laravel-email-exceptions) (abandoned), updated for **Laravel 9–12** and **PHP 8.2+**.

Emails exception details to your development team whenever an unhandled exception is thrown. Supports:

- Per-exception throttling (suppress duplicate emails for a configurable window)
- Global throttling (cap total emails per time period during outages)
- `dontEmail` and `dontThrottle` class lists
- Custom app-level skip logic via `appSpecificDontEmail()`
- Publishable email Blade view
- All configuration via environment variables

---

## Requirements

- PHP **^8.2**
- Laravel **9.x / 10.x / 11.x / 12.x**

---

## Installation

```bash
composer require inlinx-ltd/laravel-email-exceptions
```

### 1. Update `app/Exceptions/Handler.php`

Change the parent class import from the Laravel default to `EmailHandler`:

```php
// Before
use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;

// After
use InlinxLtd\LaravelEmailExceptions\Exceptions\EmailHandler as ExceptionHandler;
```

Your Handler continues to extend `ExceptionHandler` as normal — all existing `register()` callbacks and `unauthenticated()` overrides remain untouched:

```php
<?php

namespace App\Exceptions;

use InlinxLtd\LaravelEmailExceptions\Exceptions\EmailHandler as ExceptionHandler;
use Illuminate\Auth\AuthenticationException;
use Throwable;

class Handler extends ExceptionHandler
{
    public function register(): void
    {
        // Your existing reportable() callbacks stay here — they still fire.
        $this->reportable(function (Throwable $e) {
            // ...
        });
    }
}
```

### 2. Publish config and view (optional)

```bash
php artisan vendor:publish --provider="InlinxLtd\LaravelEmailExceptions\EmailExceptionsServiceProvider"
```

This publishes:
- `config/laravelEmailExceptions.php`
- `resources/views/vendor/laravelEmailExceptions/emailException.blade.php`

### 3. Configure environment variables

Add to `.env`:

```dotenv
EXCEPTION_EMAIL_ENABLED=true
EXCEPTION_EMAIL_TO=dev@yourcompany.com
EXCEPTION_EMAIL_FROM=noreply@yourcompany.com
# EXCEPTION_EMAIL_SUBJECT=   # Optional custom subject
```

Make sure your `MAIL_*` settings are also configured.

---

## Configuration

Published config at `config/laravelEmailExceptions.php`:

```php
'ErrorEmail' => [
    // Enable/disable globally
    'email' => env('EXCEPTION_EMAIL_ENABLED', true),

    // Exception classes never emailed (beyond Laravel's built-in $dontReport)
    'dontEmail' => [
        // \Illuminate\Validation\ValidationException::class,
        // \Illuminate\Auth\AuthenticationException::class,
    ],

    // Per-exception throttle — suppress duplicate emails for the same exception
    'throttle' => true,
    'throttleCacheDriver' => env('CACHE_STORE', env('CACHE_DRIVER', 'file')),
    'throttleDurationMinutes' => 5,

    // Classes exempt from throttling
    'dontThrottle' => [],

    // Global cap — prevents email floods during widespread outages
    'globalThrottle' => true,
    'globalThrottleLimit' => 20,
    'globalThrottleDurationMinutes' => 30,

    'toEmailAddress' => env('EXCEPTION_EMAIL_TO', null),
    'fromEmailAddress' => env('EXCEPTION_EMAIL_FROM', env('MAIL_FROM_ADDRESS', null)),
    'emailSubject' => env('EXCEPTION_EMAIL_SUBJECT', null),
    'sentryFallback' => env('EXCEPTION_EMAIL_SENTRY_FALLBACK', true),
],
```

### Throttling explained

**Per-exception throttle** (`throttle`): A unique key is derived from the exception class + message + code. If the same exception fires again within `throttleDurationMinutes`, no further email is sent until the window expires.

**Global throttle** (`globalThrottle`): Regardless of exception type, once `globalThrottleLimit` emails have been sent within `globalThrottleDurationMinutes`, all further emails are suppressed for the remainder of that window. Protects your inbox during major outages.

> **Tip for multi-server deployments**: Set `throttleCacheDriver` to `redis` so throttle state is shared across all instances.

### Sentry fallback (optional)

When the exception email itself cannot be sent (SES paused, bad credentials, etc.), the package can emit **one Sentry event per UTC day per distinct mail-error message**. It does **not** capture application exceptions automatically.

Install `sentry/sentry-laravel` in the host app, set `SENTRY_LARAVEL_DSN`, and **do not** call `Sentry\Laravel\Integration::handles()` in `bootstrap/app.php`. Capture is manual from this package's mail-failure catch only.

```dotenv
SENTRY_LARAVEL_DSN=
SENTRY_TRACES_SAMPLE_RATE=0
EXCEPTION_EMAIL_SENTRY_FALLBACK=true
```

Set `EXCEPTION_EMAIL_SENTRY_FALLBACK=false` to disable the fallback while leaving the DSN in place.

---

## Advanced: custom skip logic

Override `appSpecificDontEmail()` in your `Handler.php` to add logic beyond class matching:

```php
protected function appSpecificDontEmail(Throwable $exception): bool
{
    // Skip exceptions triggered by health-check endpoints
    if (request()->is('health') || request()->is('ping')) {
        return true;
    }

    return false;
}
```

---

## Changelog from original

| Change | Detail |
|---|---|
| Laravel 12 support | `illuminate/support ^9-12`, PHP `^8.2` |
| Namespace | `Abrigham\` → `InlinxLtd\` |
| Cache key bug fixed | Cache key was stored in an instance variable — reused across multiple exceptions in the same process. Now computed fresh each call. |
| Cascade protection | `mailException()` wrapped in try-catch; mail failures are logged and never re-throw into the exception handler |
| Carbon | `\DateTime` replaced with `now()->addMinutes()` |
| `$defer` removed | Deprecated `ServiceProvider::$defer` property removed |
| Env-driven config | `toEmailAddress`, `fromEmailAddress`, `emailSubject` now read from env vars |
| Dual cache env | `throttleCacheDriver` falls back through `CACHE_STORE` → `CACHE_DRIVER` → `file` |
| PHPUnit 10+ | Tests updated with attribute-based `#[DataProvider]` |

---

## License

MIT — see [LICENSE.txt](LICENSE.txt)

Original work © Aaron Brigham. Fork maintained by [Inlinx Ltd](https://github.com/inlinx-ltd).
