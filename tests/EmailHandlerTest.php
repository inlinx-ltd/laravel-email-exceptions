<?php

namespace InlinxLtd\LaravelEmailExceptions\Tests;

use BadMethodCallException;
use Exception;
use Illuminate\Support\Facades\Mail;
use InlinxLtd\LaravelEmailExceptions\EmailExceptionsServiceProvider;
use InlinxLtd\LaravelEmailExceptions\ExceptionEmailService;
use InlinxLtd\LaravelEmailExceptions\Mail\ExceptionMail;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Tests for ExceptionEmailService — the recommended integration class.
 *
 * All methods under test are public, so no Mockery tricks or protected-method
 * workarounds are needed. Mail is intercepted via Mail::fake() (Laravel's built-in
 * test double) and cache is kept in the array driver for full isolation.
 */
class EmailHandlerTest extends TestCase
{
    private ExceptionEmailService $service;

    protected function getPackageProviders($app): array
    {
        return [EmailExceptionsServiceProvider::class];
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new ExceptionEmailService();

        config([
            'laravelEmailExceptions.ErrorEmail.email'                         => true,
            'laravelEmailExceptions.ErrorEmail.toEmailAddress'                => 'dev@example.com',
            'laravelEmailExceptions.ErrorEmail.fromEmailAddress'              => 'noreply@example.com',
            'laravelEmailExceptions.ErrorEmail.emailSubject'                  => null,
            'laravelEmailExceptions.ErrorEmail.throttle'                      => true,
            'laravelEmailExceptions.ErrorEmail.throttleCacheDriver'           => 'array',
            'laravelEmailExceptions.ErrorEmail.throttleDurationMinutes'       => 5,
            'laravelEmailExceptions.ErrorEmail.dontThrottle'                  => [],
            'laravelEmailExceptions.ErrorEmail.dontEmail'                     => [],
            'laravelEmailExceptions.ErrorEmail.globalThrottle'                => true,
            'laravelEmailExceptions.ErrorEmail.globalThrottleLimit'           => 20,
            'laravelEmailExceptions.ErrorEmail.globalThrottleDurationMinutes' => 30,
            'laravelEmailExceptions.ErrorEmail.sentryFallback'                => true,
        ]);
    }

    // -----------------------------------------------------------------------
    // shouldNotify()
    // -----------------------------------------------------------------------

    public function test_should_notify_returns_false_when_email_disabled(): void
    {
        config(['laravelEmailExceptions.ErrorEmail.email' => false]);

        $this->assertFalse($this->service->shouldNotify(new Exception('test')));
    }

    public function test_should_notify_returns_false_when_to_address_missing(): void
    {
        config(['laravelEmailExceptions.ErrorEmail.toEmailAddress' => null]);

        $this->assertFalse($this->service->shouldNotify(new Exception('test')));
    }

    public function test_should_notify_returns_false_when_from_address_missing(): void
    {
        config(['laravelEmailExceptions.ErrorEmail.fromEmailAddress' => null]);

        $this->assertFalse($this->service->shouldNotify(new Exception('test')));
    }

    public function test_should_notify_returns_false_when_exception_in_dont_email_list(): void
    {
        config(['laravelEmailExceptions.ErrorEmail.dontEmail' => [Exception::class]]);

        $this->assertFalse($this->service->shouldNotify(new Exception('dontEmail_' . uniqid())));
    }

    public function test_should_notify_returns_false_on_second_call_when_throttled(): void
    {
        $e = new Exception('throttle_test_' . uniqid());

        $this->assertTrue($this->service->shouldNotify($e));  // first — not throttled, sets cache
        $this->assertFalse($this->service->shouldNotify($e)); // second — now throttled
    }

    public function test_should_notify_returns_false_when_globally_throttled(): void
    {
        config([
            'laravelEmailExceptions.ErrorEmail.globalThrottleLimit' => 1,
            'laravelEmailExceptions.ErrorEmail.throttle'            => false, // isolate to global throttle only
        ]);

        $this->assertTrue($this->service->shouldNotify(new Exception('global_1_' . uniqid())));  // counter → 1
        $this->assertFalse($this->service->shouldNotify(new Exception('global_2_' . uniqid()))); // at limit
    }

    public function test_should_notify_returns_true_when_all_checks_pass(): void
    {
        config(['laravelEmailExceptions.ErrorEmail.globalThrottle' => false]);

        $this->assertTrue($this->service->shouldNotify(new Exception('all_pass_' . uniqid())));
    }

    // -----------------------------------------------------------------------
    // send()
    // -----------------------------------------------------------------------

    #[DataProvider('mailExceptionProvider')]
    public function test_send_dispatches_exception_mail(?string $subject): void
    {
        Mail::fake();

        config(['laravelEmailExceptions.ErrorEmail.emailSubject' => $subject]);

        $this->service->send(new Exception('Test Exception'));

        Mail::assertSent(ExceptionMail::class);
    }

    public static function mailExceptionProvider(): array
    {
        return [
            'default subject' => [null],
            'custom subject'  => ['Your custom alert subject'],
        ];
    }

    public function test_send_does_not_propagate_mail_exception(): void
    {
        // Configure an invalid mailer to force a failure at transport level
        config(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => '']);

        // send() must swallow any Throwable from Mail and never rethrow it
        $this->service->send(new Exception('Mail failure test'));

        $this->assertTrue(true, 'send() reached this line without throwing');
    }

    public function test_should_capture_sentry_fallback_is_false_when_disabled(): void
    {
        config(['laravelEmailExceptions.ErrorEmail.sentryFallback' => false]);

        $this->assertFalse($this->service->shouldCaptureSentryFallback(new Exception('ses paused')));
    }

    public function test_should_capture_sentry_fallback_is_false_without_dsn(): void
    {
        $this->assertFalse($this->service->shouldCaptureSentryFallback(new Exception('ses paused')));
    }

    public function test_sentry_fallback_cache_key_differs_by_mail_error_message(): void
    {
        $paused = new Exception('Sending paused for this account.');
        $signature = new Exception('The request signature we calculated does not match');

        $this->assertNotSame(
            $this->service->sentryFallbackCacheKey($paused),
            $this->service->sentryFallbackCacheKey($signature),
        );
    }

    public function test_sentry_fallback_cache_key_is_stable_for_same_mail_error(): void
    {
        $e = new Exception('Sending paused for this account.');

        $this->assertSame(
            $this->service->sentryFallbackCacheKey($e),
            $this->service->sentryFallbackCacheKey($e),
        );
    }

    // -----------------------------------------------------------------------
    // isGloballyThrottled()
    // -----------------------------------------------------------------------

    #[DataProvider('globalThrottleProvider')]
    public function test_is_globally_throttled(
        bool $globalThrottle,
        int  $globalThrottleLimit,
        int  $callCount,
        bool $expectedLastResult,
    ): void {
        config([
            'laravelEmailExceptions.ErrorEmail.globalThrottle'                => $globalThrottle,
            'laravelEmailExceptions.ErrorEmail.globalThrottleLimit'           => $globalThrottleLimit,
            'laravelEmailExceptions.ErrorEmail.globalThrottleDurationMinutes' => 30,
        ]);

        $result = false;

        for ($i = 0; $i < $callCount; $i++) {
            $result = $this->service->isGloballyThrottled();
        }

        $this->assertSame($expectedLastResult, $result);
    }

    public static function globalThrottleProvider(): array
    {
        return [
            'global throttle disabled'     => [false, 20, 1, false],
            'first call — not throttled'   => [true,  20, 1, false],
            'under limit — not throttled'  => [true,  20, 5, false],
            'exactly at limit — throttled' => [true,   1, 2, true],
            'over limit — throttled'       => [true,   5, 6, true],
        ];
    }

    // -----------------------------------------------------------------------
    // isThrottled()
    // -----------------------------------------------------------------------

    #[DataProvider('throttleProvider')]
    public function test_is_throttled(
        bool $throttle,
        bool $inDontThrottleList,
        bool $callTwice,
        bool $expectedSecondCall,
    ): void {
        config([
            'laravelEmailExceptions.ErrorEmail.throttle'               => $throttle,
            'laravelEmailExceptions.ErrorEmail.dontThrottle'           => $inDontThrottleList ? [Exception::class] : [],
            'laravelEmailExceptions.ErrorEmail.throttleDurationMinutes' => 5,
        ]);

        $e = new Exception('isThrottled_' . uniqid());

        // The first call must never be throttled — it only sets the cache.
        $this->assertFalse($this->service->isThrottled($e), 'First call should not be throttled');

        if ($callTwice) {
            $this->assertSame($expectedSecondCall, $this->service->isThrottled($e));
        }
    }

    public static function throttleProvider(): array
    {
        return [
            'throttle disabled'           => [false, false, true, false],
            'in dont-throttle list'       => [true,  true,  true, false],
            'throttle on — second is hit' => [true,  false, true, true],
        ];
    }

    // -----------------------------------------------------------------------
    // buildThrottleCacheKey()
    // -----------------------------------------------------------------------

    #[DataProvider('buildThrottleCacheKeyProvider')]
    public function test_build_throttle_cache_key(\Throwable $exception, string $expected): void
    {
        $this->assertSame($expected, $this->service->buildThrottleCacheKey($exception));
    }

    public static function buildThrottleCacheKeyProvider(): array
    {
        return [
            'basic exception'        => [new Exception('Test-Exception', 1),                       'laravelEmailExceptionExceptionTestException1'],
            'special chars stripped' => [new Exception('Test$Some Exception', 2),                  'laravelEmailExceptionExceptionTestSomeException2'],
            'BadMethodCallException' => [new BadMethodCallException('Test-Third_Exception', 3),    'laravelEmailExceptionBadMethodCallExceptionTestThirdException3'],
        ];
    }

    // -----------------------------------------------------------------------
    // isInList()
    // -----------------------------------------------------------------------

    #[DataProvider('isInListProvider')]
    public function test_is_in_list(\Throwable $exception, array $list, bool $expected): void
    {
        $this->assertSame($expected, $this->service->isInList($list, $exception));
    }

    public static function isInListProvider(): array
    {
        return [
            'in list'     => [new Exception('Test'), [Exception::class],             true],
            'not in list' => [new Exception('Test'), [BadMethodCallException::class], false],
        ];
    }
}
