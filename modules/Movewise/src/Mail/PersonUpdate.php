<?php

declare(strict_types=1);

namespace Modules\Movewise\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** A short email to a Movewise person about their introduction (accepted, not answered yet, phone code fallback). */
final class PersonUpdate extends Mailable
{
    /** @param  list<array{0: string, 1: string}>  $links  label => url */
    public function __construct(public readonly string $heading, public readonly string $body, public readonly array $links, public readonly string $lang) {}

    public function envelope(): Envelope
    {
        return new Envelope(from: new Address((string) config('movewise.mail_from'), 'Movewise Homes'), subject: $this->heading);
    }

    public function content(): Content
    {
        return new Content(view: 'movewise::mail.update');
    }
}
