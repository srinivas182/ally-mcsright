<?php

declare(strict_types=1);

namespace Modules\Movewise\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Tells the agent politely that an introduction ended (the person withdrew, or a refund was decided), with no reasons the person didn't give. */
final class IntroductionClosed extends Notification
{
    use Queueable;

    public function __construct(public readonly string $subject, public readonly string $body, public readonly string $link) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)->subject($this->subject)->line($this->body)->action('Open Movewise', $this->link);
    }
}
