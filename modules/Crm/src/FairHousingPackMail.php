<?php

declare(strict_types=1);

namespace Modules\Crm;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** The monthly Fair Housing audit pack, with the PDF attached (specs/grow/12). */
final class FairHousingPackMail extends Notification
{
    use Queueable;

    public function __construct(public readonly string $label, public readonly int $checked, public readonly int $flagged, public readonly string $pdf, public readonly string $filename) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)->subject('Fair Housing audit pack: '.$this->label)
            ->line('RightAlly checked '.number_format($this->checked).' pieces of outgoing wording in '.$this->label.' and flagged '.number_format($this->flagged).'.')
            ->line('The attached pack lists each one by channel, what happened to it and why. Keep it with your Fair Housing records.')
            ->action('See all packs', url('/settings/fair-housing'))
            ->attachData($this->pdf, $this->filename, ['mime' => 'application/pdf']);
    }
}
