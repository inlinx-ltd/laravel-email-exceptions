<?php

namespace InlinxLtd\LaravelEmailExceptions;

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
    }

    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__ . '/config/laravelEmailExceptions.php',
            'laravelEmailExceptions'
        );
    }
}
