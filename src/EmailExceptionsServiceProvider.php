<?php

namespace InlinxLtd\LaravelEmailExceptions;

use Throwable;
use Illuminate\Support\ServiceProvider;

class EmailExceptionsServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__ . '/views', 'laravelEmailExceptions');

        $this->publishes([
            __DIR__ . '/config/laravelEmailExceptions.php' => config_path('laravelEmailExceptions.php'),
        ], 'config');

        $this->publishes([
            __DIR__ . '/views' => resource_path('views/vendor/laravelEmailExceptions'),
        ], 'views');

        // Register exception email notifications via reportable() callback.
        //
        // This approach works in every execution context — HTTP requests, queue
        // workers, scheduled commands, and Artisan — because:
        //   - In HTTP context  : directly reaches App\Exceptions\Handler.
        //   - In console context: Collision's reportable() delegates to its inner
        //     handler, so the callback still fires regardless of the class wrapping.
        //
        // The base handler's shouldntReport() check runs BEFORE any reportable()
        // callbacks, so ValidationException, AuthenticationException, etc. are
        // already filtered out before we even see them here.
        $service = new ExceptionEmailService();

        app('Illuminate\Contracts\Debug\ExceptionHandler')->reportable(
            static function (Throwable $exception) use ($service): void {
                if ($service->shouldNotify($exception)) {
                    $service->send($exception);
                }
            }
        );
    }

    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__ . '/config/laravelEmailExceptions.php',
            'laravelEmailExceptions'
        );

        $this->app->singleton(ExceptionEmailService::class);
    }
}
