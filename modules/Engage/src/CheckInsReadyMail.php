<?php

declare(strict_types=1);

namespace Modules\Engage;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Monday: this week's check-in emails are drafted and waiting for the agent to read and send (EN1, D23). */
final class CheckInsReadyMail extends Notification
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
        return (new MailMessage)->subject($this->count === 1 ? '1 check-in email is ready to send' : "{$this->count} check-in emails are ready to send")
            ->line("Your database assistant wrote {$this->count} short, personal check-in ".($this->count === 1 ? 'email' : 'emails').' to people you have not been in touch with for a while.')
            ->line('Nothing is sent until you press Send. Change any of them first if you like.')
            ->action('Read and send', url('/engage'));
    }
}
