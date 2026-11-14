<?php

declare(strict_types=1);

namespace Modules\Crm\Searches;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;

/** New listings and price drops for a saved search, with the MLS attribution for each and a one-click unsubscribe. */
final class SearchAlertMail extends Mailable
{
    use Queueable;

    /**
     * @param  list<array<string, mixed>>  $listings
     */
    public function __construct(public readonly string $searchName, public readonly string $firstName, public readonly array $listings, public readonly string $brokerage,
        public readonly ?string $agent, public readonly ?string $agentPhone, public readonly string $stopUrl, public readonly string $replyAddress) {}

    public function envelope(): Envelope
    {
        $new = count(array_filter($this->listings, fn (array $l): bool => $l['kind'] === 'new'));
        $drops = count($this->listings) - $new;
        $subject = $new > 0 ? "{$new} new ".($new === 1 ? 'home' : 'homes').' for '.$this->searchName : "Price drop: {$this->searchName}";
        if ($new > 0 && $drops > 0) {
            $subject .= " (+{$drops} price ".($drops === 1 ? 'drop' : 'drops').')';
        }

        return new Envelope(from: new Address((string) config('mail.from.address'), ($this->agent ?? $this->brokerage).' · '.$this->brokerage),
            replyTo: [new Address($this->replyAddress)], subject: $subject);
    }

    public function headers(): Headers
    {
        return new Headers(text: ['List-Unsubscribe' => '<'.$this->stopUrl.'>', 'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click']);
    }

    public function content(): Content
    {
        return new Content(view: 'crm::alerts.mail');
    }
}
