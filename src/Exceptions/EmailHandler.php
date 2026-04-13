<?php

namespace InlinxLtd\LaravelEmailExceptions\Exceptions;

use Throwable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;

class EmailHandler extends ExceptionHandler
{
    /**
     * Global throttle cache key shared across all exception types.
     */
    protected string $globalThrottleCacheKey = 'email_exception_global';

    /**
     * Report or log an exception, and email it if it passes all checks.
     */
    public function report(Throwable $exception): void
    {
        if ($this->shouldMail($exception)) {
            $this->mailException($exception);
        }

        $this->callParentReport($exception);
    }

    /**
     * Isolated to make testing easier.
     */
    protected function callParentReport(Throwable $exception): void
    {
        parent::report($exception);
    }

    /**
     * Determine whether this exception should trigger an email.
     */
    protected function shouldMail(Throwable $exception): bool
    {
        if (
            config('laravelEmailExceptions.ErrorEmail.email') !== true
            || !config('laravelEmailExceptions.ErrorEmail.toEmailAddress')
            || !config('laravelEmailExceptions.ErrorEmail.fromEmailAddress')
            || $this->shouldntReport($exception)
            || $this->isInDontEmailList($exception)
            || $this->appSpecificDontEmail($exception)
            || $this->isThrottled($exception)
            || $this->isGloballyThrottled()
        ) {
            return false;
        }

        return true;
    }

    /**
     * Override this in your app's Handler to add custom skip logic beyond class matching.
     */
    protected function appSpecificDontEmail(Throwable $exception): bool
    {
        return false;
    }

    /**
     * Send the exception email, swallowing any mail failures to prevent cascading exceptions.
     */
    protected function mailException(Throwable $exception): void
    {
        try {
            $toEmail    = config('laravelEmailExceptions.ErrorEmail.toEmailAddress');
            $fromEmail  = config('laravelEmailExceptions.ErrorEmail.fromEmailAddress');
            $data       = ['exception' => $exception];

            Mail::send(
                'laravelEmailExceptions::emailException',
                $data,
                static function ($message) use ($toEmail, $fromEmail, $exception): void {
                    $default = 'An Exception has been thrown on '
                        . config('app.name', 'unknown')
                        . ' (' . config('app.env', 'unknown') . ')'
                        . ' — ' . get_class($exception);

                    $subject = config('laravelEmailExceptions.ErrorEmail.emailSubject') ?: $default;

                    $message->from($fromEmail)->to($toEmail)->subject($subject);
                }
            );
        } catch (Throwable $mailException) {
            // Log mail failures but never let them bubble up into the exception handler itself
            Log::error('LaravelEmailExceptions: failed to send exception email', [
                'mail_error'         => $mailException->getMessage(),
                'original_exception' => get_class($exception),
            ]);
        }
    }

    /**
     * Check per-exception throttling. Returns true if this exact exception was
     * already emailed within the configured throttle window.
     */
    protected function isThrottled(Throwable $exception): bool
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
     * Check global throttling. Returns true when the per-period email cap has been reached.
     */
    protected function isGloballyThrottled(): bool
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
    protected function buildThrottleCacheKey(Throwable $exception): string
    {
        return preg_replace(
            '/[^A-Za-z0-9]/',
            '',
            'laravelEmailException' . get_class($exception) . $exception->getMessage() . $exception->getCode()
        );
    }

    /**
     * Resolved cache store for throttling.
     */
    protected function cacheStore(): \Illuminate\Contracts\Cache\Repository
    {
        return Cache::store(config('laravelEmailExceptions.ErrorEmail.throttleCacheDriver'));
    }

    /**
     * Check if the exception matches any class in a given list.
     */
    protected function isInList(array $list, Throwable $exception): bool
    {
        foreach ($list as $type) {
            if ($exception instanceof $type) {
                return true;
            }
        }

        return false;
    }

    protected function isInDontThrottleList(Throwable $exception): bool
    {
        return $this->isInList(
            config('laravelEmailExceptions.ErrorEmail.dontThrottle', []),
            $exception
        );
    }

    protected function isInDontEmailList(Throwable $exception): bool
    {
        return $this->isInList(
            config('laravelEmailExceptions.ErrorEmail.dontEmail', []),
            $exception
        );
    }
}
