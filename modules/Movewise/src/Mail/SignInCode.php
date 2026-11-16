<?php

declare(strict_types=1);

namespace Modules\Movewise\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** The 6-digit sign-in code for Movewise (specs/movewise/02). */
final class SignInCode extends Mailable
{
    public function __construct(public readonly string $code, public readonly string $lang) {}

    public function envelope(): Envelope
    {
        return new Envelope(from: new Address((string) config('movewise.mail_from', 'hello@movewisehomes.com'), 'Movewise Homes'),
            subject: ($this->lang === 'es' ? 'Tu código de Movewise: ' : 'Your Movewise code: ').$this->code);
    }

    public function content(): Content
    {
        return new Content(view: 'movewise::mail.code', with: ['code' => $this->code, 'lang' => $this->lang]);
    }
}
