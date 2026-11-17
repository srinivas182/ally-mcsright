<?php

declare(strict_types=1);

namespace Modules\ListIq;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** Showing emails (SH1): the request to the listing agent, the confirmation or decline, and the feedback ask. Plain, short, in one place. */
final class ShowingMail extends Mailable
{
    use Queueable;

    /**
     * @param  list<string>  $lines  already escaped where they carry people's words
     */
    public function __construct(public readonly string $kind, public readonly string $subjectLine, public readonly array $lines, public readonly ?string $button = null,
        public readonly ?string $url = null) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->subjectLine);
    }

    public function content(): Content
    {
        $p = implode('', array_map(fn (string $l): string => '<p style="margin:0 0 12px">'.$l.'</p>', $this->lines));
        $b = $this->url !== null ? '<p><a href="'.e($this->url).'" style="display:inline-block;padding:12px 18px;background:#111;color:#fff;border-radius:6px;text-decoration:none">'.e((string) $this->button).'</a></p>' : '';

        return new Content(htmlString: '<div style="font:16px/1.5 Arial,sans-serif;max-width:560px">'.$p.$b.'</div>');
    }
}
