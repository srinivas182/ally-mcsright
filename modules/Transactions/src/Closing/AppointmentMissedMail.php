<?php

declare(strict_types=1);

namespace Modules\Transactions\Closing;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** An appointment passed without being marked done, and the deadlines that depend on it. */
final class AppointmentMissedMail extends Notification
{
    use Queueable;

    /**
     * @param  list<string>  $depends
     */
    public function __construct(public readonly string $street, public readonly string $title, public readonly array $depends, public readonly string $path) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)->subject("Did the {$this->title} happen? {$this->street}")
            ->line("The {$this->title} at {$this->street} has passed and is not marked done.");
        if ($this->depends !== []) {
            $mail->line('These deadlines depend on it: '.implode('; ', $this->depends).'.');
        }

        return $mail->action('Mark it done or rebook it', url($this->path));
    }
}
