<?php

declare(strict_types=1);

namespace Modules\Sites\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;

/** The monthly home-value report (specs/grow/03): the range, how it moved, homes for sale nearby, a one-click stop. */
final class HomeReportMail extends Mailable
{
    use Queueable;

    /**
     * @param  array<string, mixed>|null  $estimate  low, mid, high, comps
     * @param  list<array<string, mixed>>  $nearby
     */
    public function __construct(public readonly string $address, public readonly string $firstName, public readonly ?array $estimate, public readonly ?float $change,
        public readonly array $nearby, public readonly string $brokerage, public readonly ?string $agent, public readonly ?string $agentPhone, public readonly string $stopUrl,
        public readonly string $replyAddress, public readonly ?string $dashboardUrl = null) {}

    public function envelope(): Envelope
    {
        return new Envelope(from: new Address((string) config('mail.from.address'), ($this->agent ?? $this->brokerage).' · '.$this->brokerage),
            replyTo: [new Address($this->replyAddress)], subject: 'Your home value this month: '.$this->address);
    }

    public function headers(): Headers
    {
        return new Headers(text: ['List-Unsubscribe' => '<'.$this->stopUrl.'>', 'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click']);
    }

    public function content(): Content
    {
        return new Content(view: 'sites::mail.home-report');
    }
}
