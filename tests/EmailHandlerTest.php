<?php

namespace InlinxLtd\LaravelEmailExceptions\Tests;

use BadMethodCallException;
use Exception;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use InlinxLtd\LaravelEmailExceptions\Exceptions\EmailHandler;
use Mockery;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class EmailHandlerTest extends TestCase
{
    protected EmailHandler $emailHandlerMock;

    protected function setUp(): void
    {
        parent::setUp();

        $this->emailHandlerMock = Mockery::mock(EmailHandler::class)
            ->makePartial()
            ->shouldAllowMockingProtectedMethods();
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    // -----------------------------------------------------------------------
    // report()
    // -----------------------------------------------------------------------

    #[DataProvider('reportProvider')]
    public function test_report(bool $shouldMail): void
    {
        $exception = new Exception('Test Exception');

        config(['laravelEmailExceptions.ErrorEmail.email' => false]);

        $this->emailHandlerMock
            ->shouldReceive('shouldMail')
            ->with($exception)
            ->once()
            ->andReturn($shouldMail);

        if ($shouldMail) {
            $this->emailHandlerMock
                ->shouldReceive('mailException')
                ->with($exception)
                ->once()
                ->andReturn(null);
        } else {
            $this->emailHandlerMock->shouldNotReceive('mailException');
        }

        $this->emailHandlerMock
            ->shouldReceive('callParentReport')
            ->with($exception)
            ->once()
            ->andReturn(null);

        $this->assertNull($this->emailHandlerMock->report($exception));
    }

    public static function reportProvider(): array
    {
        return [
            'should mail false' => [false],
            'should mail true'  => [true],
        ];
    }

    // -----------------------------------------------------------------------
    // shouldMail()
    // -----------------------------------------------------------------------

    #[DataProvider('shouldMailProvider')]
    public function test_should_mail(
        bool   $email,
        mixed  $toEmailAddress,
        mixed  $fromEmailAddress,
        bool   $shouldntReportReturn,
        bool   $isInDontEmailListReturn,
        bool   $isThrottledReturn,
        bool   $appSpecificDontEmailReturn,
        bool   $isGloballyThrottledReturn,
        bool   $expected,
    ): void {
        $exception = new Exception('Test Exception');

        config([
            'laravelEmailExceptions.ErrorEmail.email'            => $email,
            'laravelEmailExceptions.ErrorEmail.toEmailAddress'   => $toEmailAddress,
            'laravelEmailExceptions.ErrorEmail.fromEmailAddress' => $fromEmailAddress,
        ]);

        if ($email && $toEmailAddress && $fromEmailAddress) {
            $this->emailHandlerMock
                ->shouldReceive('shouldntReport')
                ->with($exception)->once()->andReturn($shouldntReportReturn);

            if (!$shouldntReportReturn) {
                $this->emailHandlerMock
                    ->shouldReceive('isInDontEmailList')
                    ->with($exception)->once()->andReturn($isInDontEmailListReturn);

                if (!$isInDontEmailListReturn) {
                    $this->emailHandlerMock
                        ->shouldReceive('appSpecificDontEmail')
                        ->with($exception)->once()->andReturn($appSpecificDontEmailReturn);

                    if (!$appSpecificDontEmailReturn) {
                        $this->emailHandlerMock
                            ->shouldReceive('isThrottled')
                            ->with($exception)->once()->andReturn($isThrottledReturn);

                        if (!$isThrottledReturn) {
                            $this->emailHandlerMock
                                ->shouldReceive('isGloballyThrottled')
                                ->withNoArgs()->once()->andReturn($isGloballyThrottledReturn);
                        } else {
                            $this->emailHandlerMock->shouldNotReceive('isGloballyThrottled');
                        }
                    } else {
                        $this->emailHandlerMock->shouldNotReceive('isThrottled');
                        $this->emailHandlerMock->shouldNotReceive('isGloballyThrottled');
                    }
                } else {
                    $this->emailHandlerMock->shouldNotReceive('appSpecificDontEmail');
                    $this->emailHandlerMock->shouldNotReceive('isThrottled');
                    $this->emailHandlerMock->shouldNotReceive('isGloballyThrottled');
                }
            } else {
                $this->emailHandlerMock->shouldNotReceive('isInDontEmailList');
                $this->emailHandlerMock->shouldNotReceive('appSpecificDontEmail');
                $this->emailHandlerMock->shouldNotReceive('isThrottled');
                $this->emailHandlerMock->shouldNotReceive('isGloballyThrottled');
            }
        }

        $this->assertEquals($expected, $this->emailHandlerMock->shouldMail($exception));
    }

    public static function shouldMailProvider(): array
    {
        return [
            'email off' => [false, false, false, false, false, false, false, false, false],
            'email on no addresses' => [true, false, false, false, false, false, false, false, false],
            'email on to only' => [true, 'dev@example.com', false, false, false, false, false, false, false],
            'passes all checks' => [true, 'dev@example.com', 'noreply@example.com', false, false, false, false, false, true],
            'shouldntReport true' => [true, 'dev@example.com', 'noreply@example.com', true, false, false, false, false, false],
            'in dont email list' => [true, 'dev@example.com', 'noreply@example.com', false, true, false, false, false, false],
            'throttled' => [true, 'dev@example.com', 'noreply@example.com', false, false, true, false, false, false],
            'app specific dont email' => [true, 'dev@example.com', 'noreply@example.com', false, false, false, true, false, false],
            'globally throttled' => [true, 'dev@example.com', 'noreply@example.com', false, false, false, false, true, false],
        ];
    }

    // -----------------------------------------------------------------------
    // mailException()
    // -----------------------------------------------------------------------

    #[DataProvider('mailExceptionProvider')]
    public function test_mail_exception(?string $subject): void
    {
        $exception = new Exception('Test Exception');

        config([
            'laravelEmailExceptions.ErrorEmail.toEmailAddress'   => 'dev@example.com',
            'laravelEmailExceptions.ErrorEmail.fromEmailAddress' => 'noreply@example.com',
            'laravelEmailExceptions.ErrorEmail.emailSubject'     => $subject,
        ]);

        Mail::shouldReceive('send')->once()->withAnyArgs();

        $this->assertNull($this->emailHandlerMock->mailException($exception));
    }

    public static function mailExceptionProvider(): array
    {
        return [
            'default subject' => [null],
            'custom subject'  => ['Your custom subject'],
        ];
    }

    // -----------------------------------------------------------------------
    // isGloballyThrottled()
    // -----------------------------------------------------------------------

    #[DataProvider('globalThrottleProvider')]
    public function test_is_globally_throttled(
        bool   $globalThrottle,
        string $throttleCacheDriver,
        int    $globalThrottleLimit,
        int    $globalThrottleDurationMinutes,
        bool   $cacheHas,
        int    $cacheGet,
        bool   $expected,
    ): void {
        $key = 'email_exception_global';

        config([
            'laravelEmailExceptions.ErrorEmail.globalThrottle'                => $globalThrottle,
            'laravelEmailExceptions.ErrorEmail.throttleCacheDriver'            => $throttleCacheDriver,
            'laravelEmailExceptions.ErrorEmail.globalThrottleLimit'            => $globalThrottleLimit,
            'laravelEmailExceptions.ErrorEmail.globalThrottleDurationMinutes'  => $globalThrottleDurationMinutes,
        ]);

        if (!$globalThrottle) {
            Cache::shouldReceive('store')->never();
        } else {
            $storeMock = Mockery::mock(\Illuminate\Contracts\Cache\Repository::class);

            Cache::shouldReceive('store')
                ->with($throttleCacheDriver)
                ->andReturn($storeMock);

            $storeMock->shouldReceive('has')->with($key)->once()->andReturn($cacheHas);

            if ($cacheHas) {
                $storeMock->shouldReceive('get')->with($key, 0)->once()->andReturn($cacheGet);

                if ($cacheGet >= $globalThrottleLimit) {
                    $storeMock->shouldNotReceive('increment');
                } else {
                    $storeMock->shouldReceive('increment')->with($key)->once();
                }
            } else {
                $storeMock->shouldReceive('put')->once()->withAnyArgs();
            }
        }

        $this->assertEquals($expected, $this->emailHandlerMock->isGloballyThrottled());
    }

    public static function globalThrottleProvider(): array
    {
        return [
            'global throttle off'                => [false, 'file', 20, 30, false, 0,  false],
            'in cache under limit'               => [true,  'file', 20, 30, true,  10, false],
            'in cache at limit (over)'           => [true,  'file', 20, 30, true,  20, true],
            'not in cache — first email'         => [true,  'file', 20, 30, false, 0,  false],
        ];
    }

    // -----------------------------------------------------------------------
    // isThrottled()
    // -----------------------------------------------------------------------

    #[DataProvider('throttleProvider')]
    public function test_is_throttled(
        bool   $throttle,
        string $throttleCacheDriver,
        bool   $isInDontThrottleListReturn,
        bool   $cacheHas,
        int    $throttleDurationMinutes,
        bool   $expected,
    ): void {
        $exception = new Exception('Test Exception');
        $cacheKey  = 'laravelEmailExceptionExceptionTestException0';

        config([
            'laravelEmailExceptions.ErrorEmail.throttle'              => $throttle,
            'laravelEmailExceptions.ErrorEmail.throttleCacheDriver'    => $throttleCacheDriver,
            'laravelEmailExceptions.ErrorEmail.throttleDurationMinutes' => $throttleDurationMinutes,
        ]);

        if (!$throttle) {
            $this->emailHandlerMock->shouldNotReceive('isInDontThrottleList');
            Cache::shouldReceive('store')->never();
        } else {
            $this->emailHandlerMock
                ->shouldReceive('isInDontThrottleList')
                ->with($exception)->once()->andReturn($isInDontThrottleListReturn);

            if (!$isInDontThrottleListReturn) {
                $storeMock = Mockery::mock(\Illuminate\Contracts\Cache\Repository::class);
                Cache::shouldReceive('store')->with($throttleCacheDriver)->andReturn($storeMock);

                $storeMock->shouldReceive('has')->with($cacheKey)->once()->andReturn($cacheHas);

                if (!$cacheHas) {
                    $storeMock->shouldReceive('put')->once()->withAnyArgs();
                }
            } else {
                Cache::shouldReceive('store')->never();
            }
        }

        $this->assertEquals($expected, $this->emailHandlerMock->isThrottled($exception));
    }

    public static function throttleProvider(): array
    {
        return [
            'throttle off'                  => [false, 'file', false, false, 5, false],
            'in dont throttle list'         => [true,  'file', true,  false, 5, false],
            'throttle on — in cache'        => [true,  'file', false, true,  5, true],
            'throttle on — not in cache'    => [true,  'file', false, false, 5, false],
        ];
    }

    // -----------------------------------------------------------------------
    // buildThrottleCacheKey()
    // -----------------------------------------------------------------------

    #[DataProvider('buildThrottleCacheKeyProvider')]
    public function test_build_throttle_cache_key(\Throwable $exception, string $expected): void
    {
        $this->assertEquals($expected, $this->emailHandlerMock->buildThrottleCacheKey($exception));
    }

    public static function buildThrottleCacheKeyProvider(): array
    {
        return [
            'basic exception'           => [new Exception('Test-Exception', 1),       'laravelEmailExceptionExceptionTestException1'],
            'special chars stripped'    => [new Exception('Test$Some Exception', 2),  'laravelEmailExceptionExceptionTestSomeException2'],
            'BadMethodCallException'    => [new BadMethodCallException('Test-Third_Exception', 3), 'laravelEmailExceptionBadMethodCallExceptionTestThirdException3'],
        ];
    }

    // -----------------------------------------------------------------------
    // isInList()
    // -----------------------------------------------------------------------

    #[DataProvider('isInListProvider')]
    public function test_is_in_list(\Throwable $exception, array $list, bool $expected): void
    {
        $this->assertEquals($expected, $this->emailHandlerMock->isInList($list, $exception));
    }

    public static function isInListProvider(): array
    {
        return [
            'in list'     => [new Exception('Test'), [Exception::class],            true],
            'not in list' => [new Exception('Test'), [BadMethodCallException::class], false],
        ];
    }
}
