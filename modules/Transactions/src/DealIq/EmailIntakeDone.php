<?php

declare(strict_types=1);

namespace Modules\Transactions\DealIq;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** DQ4: what happened to paperwork the agent forwarded to their Deal-IQ address. */
final class EmailIntakeDone extends Notification
{
    use Queueable;

    public function __construct(public readonly ?string $deal, public readonly string $subject, public readonly ?string $problem) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        if ($this->deal === null) {
            return (new MailMessage)->subject('Deal-IQ could not start a deal from your email')->line('About: '.$this->subject)->line((string) $this->problem)
                ->action('Open Deals', url('/deals'));
        }

        return (new MailMessage)->subject('Deal-IQ is reading your contract')->line('About: '.$this->subject)
            ->line('Deal-IQ started a new deal from the contract you forwarded. Check what it read, change anything, and confirm.')
            ->action('Check and confirm', url('/deals/'.$this->deal.'/setup'));
    }
}
