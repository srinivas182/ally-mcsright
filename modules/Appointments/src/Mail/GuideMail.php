<?php

declare(strict_types=1);

namespace Modules\Appointments\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** The seller's or buyer's guide after the appointment (AP1): what was agreed, what happens next, who to call. */
final class GuideMail extends Mailable
{
    use Queueable;

    /**
     * @param  array<string, mixed>  $answers
     */
    public function __construct(public readonly string $kind, public readonly string $firstName, public readonly array $answers, public readonly string $agent,
        public readonly string $agentPhone, public readonly string $brokerage, public readonly ?bool $agreementSigned) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->kind === 'listing' ? 'Your home sale plan, from '.$this->agent : 'Your home search plan, from '.$this->agent);
    }

    public function content(): Content
    {
        return new Content(view: 'appointments::guide');
    }
}
