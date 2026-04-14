<?php

namespace InlinxLtd\LaravelEmailExceptions\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Throwable;

/**
 * Mailable that wraps a PHP exception for alert email delivery.
 *
 * Using a proper Mailable (rather than a raw Mail::send() callback) enables
 * Mail::fake() + Mail::assertSent() in tests without any Mockery involvement.
 *
 * This class is intentionally NOT queueable: exception alerts should be
 * delivered synchronously so they are never lost in a failed queue.
 */
class ExceptionMail extends Mailable
{
    public function __construct(
        private readonly Throwable $exception,
    ) {}

    public function envelope(): Envelope
    {
        $default = 'An Exception has been thrown on '
            . config('app.name', 'unknown')
            . ' (' . config('app.env', 'unknown') . ')'
            . ' — ' . get_class($this->exception);

        return new Envelope(
            from: new Address((string) config('laravelEmailExceptions.ErrorEmail.fromEmailAddress')),
            to:   [new Address((string) config('laravelEmailExceptions.ErrorEmail.toEmailAddress'))],
            subject: config('laravelEmailExceptions.ErrorEmail.emailSubject') ?: $default,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'laravelEmailExceptions::emailException',
            with: ['exception' => $this->exception],
        );
    }
}
