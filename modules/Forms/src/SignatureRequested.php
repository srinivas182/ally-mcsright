<?php

declare(strict_types=1);

namespace Modules\Forms;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class SignatureRequested extends Notification
{
    use Queueable;

    public function __construct(public readonly string $title, public readonly string $brokerage, public readonly string $agent, public readonly string $url, public readonly bool $reminder) {}

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
            ->subject(($this->reminder ? 'Reminder: ' : '')."Please sign: {$this->title}")
            ->line("{$this->agent} at {$this->brokerage} has sent you documents to sign: {$this->title}.")
            ->action('Review and sign', $this->url)
            ->line('Wire fraud warning: never wire money based on an email or text. Payment instructions never change by email — call your agent or the title company on a number you already know before sending any money.');
    }
}
