<?php

declare(strict_types=1);

namespace Modules\Billing\HealthCheck;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** The broker's private link to their Health Check report. */
final class HealthReportMail extends Notification
{
    use Queueable;

    public function __construct(public readonly string $brokerage, public readonly string $name, public readonly string $url) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)->subject('Your Brokerage Health Check: '.$this->brokerage)
            ->greeting('Hello '.$this->name.',')
            ->line('Your Health Check report for '.$this->brokerage.' is ready: scores, what to fix first, the website check, the savings comparison and a preview of RightAlly in your colours.')
            ->action('Open my report', $this->url)
            ->line('The link is private to you and works for 180 days. You can delete the report from the report page at any time.')
            ->line('If you did not ask for this, ignore this email; nothing else will be sent.');
    }
}
