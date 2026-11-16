<?php

declare(strict_types=1);

namespace Modules\Insights;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class AnomalyMail extends Notification
{
    use Queueable;

    public function __construct(public readonly string $text) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)->subject('Worth a look: '.$this->text)->line($this->text)->action('Open your numbers', url('/insights'));
    }
}
