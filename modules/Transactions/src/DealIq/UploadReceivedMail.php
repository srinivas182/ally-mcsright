<?php

declare(strict_types=1);

namespace Modules\Transactions\DealIq;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class UploadReceivedMail extends Notification
{
    use Queueable;

    public function __construct(public readonly string $from, public readonly string $what, public readonly string $street, public readonly string $path) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)->subject("{$this->from} uploaded {$this->what} ({$this->street})")
            ->line('It is filed on the deal and being read now.')->action('Open the deal', url($this->path));
    }
}
