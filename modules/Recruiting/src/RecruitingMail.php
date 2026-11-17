<?php

declare(strict_types=1);

namespace Modules\Recruiting;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;

/**
 * One recruiting campaign email (RC2): from the recruiter by name, replies go to them, the brokerage's postal
 * address and a one-click unsubscribe in every message (CAN-SPAM).
 */
final class RecruitingMail extends Mailable
{
    use Queueable;

    public function __construct(public readonly string $subjectLine, public readonly string $body, public readonly string $fromName, public readonly ?string $replyAddress,
        public readonly string $brokerage, public readonly ?string $postalAddress, public readonly string $stopUrl) {}

    public function envelope(): Envelope
    {
        return new Envelope(from: new Address((string) config('mail.from.address'), $this->fromName.' · '.$this->brokerage),
            replyTo: $this->replyAddress !== null ? [new Address($this->replyAddress)] : [], subject: $this->subjectLine);
    }

    public function headers(): Headers
    {
        return new Headers(text: ['List-Unsubscribe' => '<'.$this->stopUrl.'>', 'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click']);
    }

    public function content(): Content
    {
        return new Content(view: 'recruiting::mail', text: 'recruiting::mail-text');
    }
}
