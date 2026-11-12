<?php

declare(strict_types=1);

namespace Modules\Core\Audit;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class AuditStreamFailing extends Notification
{
    use Queueable;

    public function __construct(public readonly string $name, public readonly string $error, public readonly bool $paused) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject($this->paused ? "Audit streaming to {$this->name} is paused" : "Audit streaming to {$this->name} is failing")
            ->line($this->paused
                ? "Delivery to {$this->name} has failed for 24 hours, so it is paused. Nothing is lost: when you resume, it continues from where it stopped."
                : "We could not deliver audit events to {$this->name}. We keep retrying every minute from where it stopped.")
            ->line('Last error: '.$this->error)
            ->action('Check audit streaming', url('/audit'));
    }
}
