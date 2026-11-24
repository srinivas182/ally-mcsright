<?php

declare(strict_types=1);

namespace Modules\Listings\Idx\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** IDX6: the signed IDX agreement, sent to the MLS or data provider with the brokerage's details. */
final class IdxAgreementMail extends Mailable
{
    use Queueable;

    /**
     * EM4: the signed PDF is attached from storage by its path. (It used to travel inside the queued email itself, and
     * a PDF's raw bytes cannot be queued, so "Send to the MLS" failed.)
     *
     * @param  array<string, string>  $facts  label => value
     */
    public function __construct(public readonly string $board, public readonly array $facts, public readonly string $path, public readonly string $fileName) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Signed IDX agreement: '.($this->facts['Brokerage'] ?? '').' ('.$this->board.')');
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.idx-agreement', with: ['board' => $this->board, 'facts' => $this->facts]);
    }

    /** @return list<Attachment> */
    public function attachments(): array
    {
        return [Attachment::fromStorageDisk('local', $this->path)->as($this->fileName)->withMime('application/pdf')];
    }
}
