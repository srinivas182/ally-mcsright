<?php

declare(strict_types=1);

namespace Modules\Pricing;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** "Maria opened your CMA" (CM1): a good moment to call. */
final class CmaOpenedMail extends Mailable
{
    use Queueable;

    public function __construct(public readonly string $who, public readonly string $home, public readonly int $views, public readonly string $url) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "{$this->who} opened your CMA for {$this->home}");
    }

    public function content(): Content
    {
        return new Content(htmlString: '<p style="font:16px/1.5 Arial,sans-serif">'.e($this->who).' just opened your CMA for '.e($this->home)
            .($this->views > 1 ? ' (view '.$this->views.')' : '').'. A good moment to call while it\'s fresh.</p><p style="font:16px/1.5 Arial,sans-serif"><a href="'.e($this->url).'">Open the CMA</a></p>');
    }
}
