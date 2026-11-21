<?php

declare(strict_types=1);

namespace Modules\Listings\Idx\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** IDX4: a short, plain email about a brokerage's MLS board (a reminder to sign, it is live, something to fix). */
final class IdxUpdateMail extends Mailable
{
    use Queueable;

    /** @param list<string> $lines */
    public function __construct(public readonly string $heading, public readonly array $lines, public readonly ?string $buttonLabel = null, public readonly ?string $buttonUrl = null) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->heading);
    }

    public function content(): Content
    {
        $html = '<div style="font:16px/1.6 system-ui,sans-serif;color:#0A1330;max-width:560px">'
            .'<h1 style="font-size:22px;margin:0 0 12px">'.e($this->heading).'</h1>';
        foreach ($this->lines as $line) {
            $html .= '<p style="margin:0 0 12px">'.e($line).'</p>';
        }
        if ($this->buttonLabel !== null && $this->buttonUrl !== null) {
            $html .= '<p style="margin:20px 0"><a href="'.e($this->buttonUrl).'" style="background:#155DFC;color:#fff;text-decoration:none;padding:12px 20px;border-radius:10px;font-weight:600;display:inline-block">'
                .e($this->buttonLabel).'</a></p>';
        }

        return new Content(htmlString: $html.'<p style="color:#52607A;font-size:13px">RightAlly</p></div>');
    }
}
