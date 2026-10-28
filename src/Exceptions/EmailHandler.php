<?php

namespace InlinxLtd\LaravelEmailExceptions\Exceptions;

use Throwable;
use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use InlinxLtd\LaravelEmailExceptions\ExceptionEmailService;
use InlinxLtd\LaravelEmailExceptions\Mail\ExceptionMail;

/**
 * Legacy integration: extend your App\Exceptions\Handler from this class
 * instead of the base Laravel handler to get exception emails via inheritance.
 *
 * @deprecated Use the ServiceProvider's automatic reportable() registration instead.
 *   The ServiceProvider now registers ExceptionEmailService via reportable() which
 *   works in ALL contexts (HTTP, queue workers, console). If you still extend this
 *   class, you will get duplicate emails in HTTP context when the ServiceProvider is
 *   also active. Remove the extends EmailHandler and revert to extending
 *   Illuminate\Foundation\Exceptions\Handler to use the ServiceProvider approach.
 *
 * Kept for backward compatibility only.
 */
class EmailHandler extends ExceptionHandler
{
    private ExceptionEmailService $emailService;

    public function __construct(\Illuminate\Contracts\Container\Container $container)
    {
        parent::__construct($container);
        $this->emailService = new ExceptionEmailService();
    }

    /**
     * Report or log an exception, and email it if it passes all checks.
     */
    public function report(Throwable $exception): void
    {
        if ($this->shouldMail($exception)) {
            $this->emailService->send($exception);
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
            || $this->appSpecificDontEmail($exception)
            || $this->emailService->isInDontEmailList($exception)
            || $this->emailService->isThrottled($exception)
            || $this->emailService->isGloballyThrottled()
        ) {
            return false;
        }

        return true;
    }

    /**
     * Override this in your app's Handler to add custom skip logic beyond class matching.
     * This hook is only available when using the legacy extends-EmailHandler approach.
     */
    protected function appSpecificDontEmail(Throwable $exception): bool
    {
        return false;
    }
}
