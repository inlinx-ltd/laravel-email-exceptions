<?php

namespace InlinxLtd\LaravelEmailExceptions;

use Throwable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use InlinxLtd\LaravelEmailExceptions\Mail\ExceptionMail;

/**
 * Standalone service that decides whether to email an exception and sends it.
 *
 * This is the recommended integration point. The ServiceProvider registers it
 * automatically via a reportable() callback, which works in every execution
 * context: HTTP requests, queue workers, scheduled commands, and Artisan.
 *
 * The EmailHandler subclass is kept for backward compatibility only.
 */
class ExceptionEmailService
{
    /** Global throttle cache key shared across all exception types. */
    protected string $globalThrottleCacheKey = 'email_exception_global';

    /**
     * Return true when the exception should trigger an alert email.
     *
     * Note: shouldntReport() is intentionally NOT checked here. The ServiceProvider
     * registers this via reportable(), and Laravel's base handler already filters
     * out shouldntReport() exceptions BEFORE calling any reportable() callbacks.
     * Checking it again would require injecting the handler, which would create
     * circular-dependency issues and unnecessary coupling.
     */
    public function shouldNotify(Throwable $exception): bool
    {
        if (
            config('laravelEmailExceptions.ErrorEmail.email') !== true
            || !config('laravelEmailExceptions.ErrorEmail.toEmailAddress')
            || !config('laravelEmailExceptions.ErrorEmail.fromEmailAddress')
            || $this->isInDontEmailList($exception)
            || $this->isThrottled($exception)
            || $this->isGloballyThrottled()
        ) {
            return false;
        }

        return true;
    }

    /**
     * Send the exception email, swallowing any mail failures to prevent cascading exceptions.
     *
     * Dispatches an ExceptionMail Mailable so that Mail::fake() + Mail::assertSent()
     * work correctly in tests without requiring Mockery on the Mail facade.
     */
    public function send(Throwable $exception): void
    {
        try {
            Mail::send(new ExceptionMail($exception));
        } catch (Throwable $mailException) {
            Log::error('LaravelEmailExceptions: failed to send exception email', [
                'mail_error'         => $mailException->getMessage(),
                'original_exception' => get_class($exception),
            ]);

            $this->reportMailFailureToSentry($mailException, $exception);
        }
    }

    /**
     * Check per-exception throttling.
     * Returns true (throttled) if this exact exception was already emailed within the window.
     */
    public function isThrottled(Throwable $exception): bool
    {
        if (
            config('laravelEmailExceptions.ErrorEmail.throttle') === false
            || $this->isInDontThrottleList($exception)
        ) {
            return false;
        }

        $key   = $this->buildThrottleCacheKey($exception);
        $store = $this->cacheStore();

        if ($store->has($key)) {
            return true;
        }

        $store->put(
            $key,
            true,
            now()->addMinutes((int) config('laravelEmailExceptions.ErrorEmail.throttleDurationMinutes', 5))
        );

        return false;
    }

    /**
     * Check global throttling.
     * Returns true when the per-period email cap has been reached.
     */
    public function isGloballyThrottled(): bool
    {
        if (config('laravelEmailExceptions.ErrorEmail.globalThrottle') === false) {
            return false;
        }

        $store = $this->cacheStore();
        $limit = (int) config('laravelEmailExceptions.ErrorEmail.globalThrottleLimit', 20);
        $ttl   = now()->addMinutes((int) config('laravelEmailExceptions.ErrorEmail.globalThrottleDurationMinutes', 30));

        if ($store->has($this->globalThrottleCacheKey)) {
            if ($store->get($this->globalThrottleCacheKey, 0) >= $limit) {
                return true;
            }
            $store->increment($this->globalThrottleCacheKey);
            return false;
        }

        $store->put($this->globalThrottleCacheKey, 1, $ttl);

        return false;
    }

    /**
     * Build a unique, filesystem-safe cache key for the given exception.
     * Computed fresh each call — avoids the bug in the original where a single
     * instance variable was shared across multiple exceptions in the same process.
     */
    public function buildThrottleCacheKey(Throwable $exception): string
    {
        return preg_replace(
            '/[^A-Za-z0-9]/',
            '',
            'laravelEmailException' . get_class($exception) . $exception->getMessage() . $exception->getCode()
        );
    }

    /**
     * Check whether the exception is in the given class list.
     */
    public function isInList(array $list, Throwable $exception): bool
    {
        foreach ($list as $type) {
            if ($exception instanceof $type) {
                return true;
            }
        }

        return false;
    }

    public function isInDontThrottleList(Throwable $exception): bool
    {
        return $this->isInList(
            config('laravelEmailExceptions.ErrorEmail.dontThrottle', []),
            $exception
        );
    }

    public function isInDontEmailList(Throwable $exception): bool
    {
        return $this->isInList(
            config('laravelEmailExceptions.ErrorEmail.dontEmail', []),
            $exception
        );
    }

    /**
     * One Sentry event per UTC day per distinct mail-transport error message.
     * No-op when Sentry is not installed or has no client (empty DSN).
     */
    public function shouldCaptureSentryFallback(Throwable $mailException): bool
    {
        if (config('laravelEmailExceptions.ErrorEmail.sentryFallback') === false) {
            return false;
        }

        if (!\function_exists('\\Sentry\\captureException')) {
            return false;
        }

        $dsn = (string) (config('sentry.dsn') ?: '');
        if ($dsn === '') {
            return false;
        }

        return !$this->cacheStore()->has($this->sentryFallbackCacheKey($mailException));
    }

    /**
     * Cache key for the Sentry mail-failure fallback.
     * Distinct by UTC date + mail exception class + message.
     */
    public function sentryFallbackCacheKey(Throwable $mailException): string
    {
        return 'laravelEmailExceptionSentry'
            . now()->utc()->toDateString()
            . hash('sha256', $mailException::class . $mailException->getMessage());
    }

    protected function reportMailFailureToSentry(Throwable $mailException, Throwable $originalException): void
    {
        if (!$this->shouldCaptureSentryFallback($mailException)) {
            return;
        }

        try {
            \Sentry\withScope(function (\Sentry\State\Scope $scope) use ($mailException, $originalException): void {
                $scope->setTag('source', 'exception_email_failure');
                $scope->setContext('original_exception', [
                    'class'   => $originalException::class,
                    'message' => $originalException->getMessage(),
                ]);
                \Sentry\captureException($mailException);
            });
            \Sentry\flush(2);

            $this->cacheStore()->put(
                $this->sentryFallbackCacheKey($mailException),
                true,
                now()->utc()->endOfDay()
            );
        } catch (Throwable $sentryException) {
            Log::error('LaravelEmailExceptions: Sentry fallback failed', [
                'sentry_error' => $sentryException->getMessage(),
                'mail_error'   => $mailException->getMessage(),
            ]);
        }
    }

    protected function cacheStore(): \Illuminate\Contracts\Cache\Repository
    {
        return Cache::store(config('laravelEmailExceptions.ErrorEmail.throttleCacheDriver'));
    }
}
