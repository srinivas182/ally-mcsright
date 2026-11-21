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
     * @param  array<string, string>  $facts  label => value
     */
    public function __construct(public readonly string $board, public readonly array $facts, public readonly string $pdf, public readonly string $fileName) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Signed IDX agreement: '.($this->facts['Brokerage'] ?? '').' ('.$this->board.')');
    }

    public function content(): Content
    {
        $rows = '';
        foreach ($this->facts as $label => $value) {
            $rows .= '<tr><th style="text-align:left;padding:4px 12px 4px 0;color:#52607A">'.e($label).'</th><td style="padding:4px 0">'.e($value).'</td></tr>';
        }

        return new Content(htmlString: '<div style="font:15px/1.6 system-ui,sans-serif;color:#0A1330;max-width:600px">'
            .'<p>Please find attached the signed IDX agreement for '.e($this->board).', with its signature certificate.</p>'
            .'<table style="border-collapse:collapse">'.$rows.'</table>'
            .'<p>Sent by RightAlly on the brokerage\'s behalf. Reply to this email with any questions.</p></div>');
    }

    /** @return list<Attachment> */
    public function attachments(): array
    {
        return [Attachment::fromData(fn (): string => $this->pdf, $this->fileName)->withMime('application/pdf')];
    }
}
