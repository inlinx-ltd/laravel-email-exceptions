<?php

return [
    'ErrorEmail' => [
        /*
         * Enable or disable emailing of exceptions entirely.
         */
        'email' => env('EXCEPTION_EMAIL_ENABLED', true),

        /*
         * Exception classes that should never be emailed.
         * These are in addition to Laravel's own $dontReport list.
         *
         * Example: ['Illuminate\Validation\ValidationException']
         */
        'dontEmail' => [],

        /*
         * Enable per-exception throttling.
         * Uniqueness is determined by: exception class + message + code.
         */
        'throttle' => true,

        /*
         * Cache store used for throttling.
         * Laravel 11+ uses CACHE_STORE; older versions use CACHE_DRIVER.
         * Supports both for backward compatibility.
         */
        'throttleCacheDriver' => env('CACHE_STORE', env('CACHE_DRIVER', 'file')),

        /*
         * How long (minutes) to suppress duplicate emails for the same exception.
         */
        'throttleDurationMinutes' => 5,

        /*
         * Exception classes that should never be throttled even when throttle=true.
         */
        'dontThrottle' => [],

        /*
         * Enable a global cap on total exception emails per time window.
         * Protects against email floods during widespread outages.
         */
        'globalThrottle' => true,

        /*
         * Maximum number of exception emails allowed within globalThrottleDurationMinutes.
         */
        'globalThrottleLimit' => 20,

        /*
         * Duration (minutes) of the global throttle window.
         */
        'globalThrottleDurationMinutes' => 30,

        /*
         * Email address(es) to send exception notifications to.
         * Accepts a string or an array of strings.
         * Example: 'dev@yourcompany.com' or ['dev@yourcompany.com', 'ops@yourcompany.com']
         */
        'toEmailAddress' => env('EXCEPTION_EMAIL_TO', null),

        /*
         * The "from" address for exception emails.
         */
        'fromEmailAddress' => env('EXCEPTION_EMAIL_FROM', env('MAIL_FROM_ADDRESS', null)),

        /*
         * Custom email subject. Leave null for the default:
         * "An Exception has been thrown on {APP_NAME} ({APP_ENV}) — {ExceptionClass}"
         */
        'emailSubject' => env('EXCEPTION_EMAIL_SUBJECT', null),
    ],
];
