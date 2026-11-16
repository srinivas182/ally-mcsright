<?php

declare(strict_types=1);

namespace Modules\Movewise\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Tells the chosen agent someone picked them on Movewise, and how long they have to accept (specs/movewise/04). */
final class IntroductionOffered extends Notification
{
    use Queueable;

    public function __construct(public readonly string $link, public readonly string $what, public readonly string $by) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Someone chose you on Movewise: accept by '.$this->by)
            ->line('A person on Movewise chose you: '.$this->what.'.')
            ->line('Accept by '.$this->by.' to be introduced. Nobody else was told about them, and you are charged only when you accept.')
            ->action('See the brief and accept', $this->link);
    }
}
