<?php

declare(strict_types=1);

namespace Modules\Outreach;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;

/** MK3 (D09): the monthly market report for one area, from the agent, with a one-click stop. */
final class MarketReportMail extends Mailable
{
    use Queueable;

    /**
     * @param  array<string, mixed>  $stats  MarketStats::forPlace
     * @param  list<array<string, mixed>>  $homes
     */
    public function __construct(public readonly string $firstName, public readonly array $stats, public readonly array $homes, public readonly string $month,
        public readonly string $brokerage, public readonly ?string $agent, public readonly ?string $agentPhone, public readonly string $stopUrl, public readonly string $replyAddress) {}

    public function envelope(): Envelope
    {
        return new Envelope(from: new Address((string) config('mail.from.address'), ($this->agent ?? $this->brokerage).' · '.$this->brokerage),
            replyTo: [new Address($this->replyAddress)], subject: $this->stats['name'].' market report, '.$this->month);
    }

    public function headers(): Headers
    {
        return new Headers(text: ['List-Unsubscribe' => '<'.$this->stopUrl.'>', 'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click']);
    }

    public function content(): Content
    {
        return new Content(view: 'outreach::mail.market-report');
    }
}
