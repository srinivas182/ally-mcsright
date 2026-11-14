<?php

declare(strict_types=1);

namespace Modules\Sites\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** The sign-in code a website visitor asked for. */
final class SignInCodeMail extends Mailable
{
    public function __construct(public readonly string $siteName, public readonly string $code) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "{$this->code} is your {$this->siteName} sign-in code");
    }

    public function content(): Content
    {
        return new Content(view: 'sites::mail.code', with: ['siteName' => $this->siteName, 'code' => $this->code]);
    }
}
