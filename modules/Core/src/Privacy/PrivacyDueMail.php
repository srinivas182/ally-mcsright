<?php

declare(strict_types=1);

namespace Modules\Core\Privacy;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Privacy requests due within a week or late (TR1). */
final class PrivacyDueMail extends Notification
{
    use Queueable;

    public function __construct(public readonly int $count) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)->subject($this->count.' privacy request(s) due soon')
            ->line('The law gives 45 days to answer a privacy request. '.$this->count.' request(s) are due within a week or are late.')
            ->action('Answer them now', url('/settings/privacy'));
    }
}
