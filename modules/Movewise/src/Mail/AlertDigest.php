<?php

declare(strict_types=1);

namespace Modules\Movewise\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;

/** New homes for a saved search (specs/movewise/02), with one-click unsubscribe (RFC 8058) and the postal address. */
final class AlertDigest extends Mailable
{
    /** @param  list<array<string, mixed>>  $homes */
    public function __construct(public readonly string $searchName, public readonly array $homes, public readonly int $total, public readonly string $stopUrl, public readonly string $lang) {}

    public function envelope(): Envelope
    {
        $n = $this->total;

        return new Envelope(from: new Address((string) config('movewise.mail_from', 'hello@movewisehomes.com'), 'Movewise Homes'),
            subject: $this->lang === 'es' ? $n.' '.($n === 1 ? 'casa nueva' : 'casas nuevas').': '.$this->searchName : $n.' new '.($n === 1 ? 'home' : 'homes').': '.$this->searchName);
    }

    public function headers(): Headers
    {
        return new Headers(text: ['List-Unsubscribe' => '<'.$this->stopUrl.'>', 'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click']);
    }

    public function content(): Content
    {
        return new Content(view: 'movewise::mail.alert', with: ['name' => $this->searchName, 'homes' => $this->homes, 'total' => $this->total, 'stop' => $this->stopUrl, 'lang' => $this->lang,
            'base' => 'https://'.config('movewise.host'), 'address' => (string) config('movewise.postal_address', '')]);
    }
}
