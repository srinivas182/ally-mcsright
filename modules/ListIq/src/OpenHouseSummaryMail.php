<?php

declare(strict_types=1);

namespace Modules\ListIq;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** The open house is over (LI2): who came, and whether they have an agent. */
final class OpenHouseSummaryMail extends Mailable
{
    use Queueable;

    public function __construct(public readonly string $home, public readonly int $count, public readonly string $rows) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "Open house at {$this->home}: {$this->count} visitor".($this->count === 1 ? '' : 's'));
    }

    public function content(): Content
    {
        return new Content(htmlString: '<div style="font:16px/1.5 Arial,sans-serif"><p>'.e((string) $this->count).' '.($this->count === 1 ? 'person' : 'people').' signed in at '.e($this->home)
            .'. Visitors without an agent are in your open-house follow-up plan.</p><ul>'.$this->rows.'</ul></div>');
    }
}
