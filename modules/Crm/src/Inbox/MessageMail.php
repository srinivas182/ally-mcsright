<?php

declare(strict_types=1);

namespace Modules\Crm\Inbox;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** A one-to-one email from an agent; replies come back to the conversation through its reply address. */
final class MessageMail extends Mailable
{
    use Queueable;

    public function __construct(public readonly string $subjectLine, public readonly string $text, public readonly string $fromName, public readonly string $replyAddress) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address((string) config('mail.from.address'), $this->fromName),
            replyTo: [new Address($this->replyAddress, $this->fromName)],
            subject: $this->subjectLine,
        );
    }

    public function content(): Content
    {
        return new Content(text: 'crm::inbox.message-text', with: ['text' => $this->text]);
    }
}
