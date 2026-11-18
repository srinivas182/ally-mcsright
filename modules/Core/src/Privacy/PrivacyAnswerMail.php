<?php

declare(strict_types=1);

namespace Modules\Core\Privacy;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** The answer to a privacy request (TR1), in plain words. */
final class PrivacyAnswerMail extends Notification
{
    use Queueable;

    public function __construct(public readonly string $name, public readonly string $brokerage, public readonly string $answer) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)->subject('Your privacy request to '.$this->brokerage)
            ->greeting('Hello '.$this->name.',')->line($this->answer);
    }
}
