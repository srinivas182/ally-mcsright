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

    public function __construct(public readonly string $brokerage, public readonly string $name, public readonly string $url, public readonly string $lang = 'en') {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $t = fn (string $key, array $with = []): string => HealthCopy::t($this->lang, $key, $with);

        return (new MailMessage)->subject($t('m.subject', ['name' => $this->brokerage]))
            ->greeting($t('m.hello', ['name' => $this->name]))
            ->line($t('m.line1', ['name' => $this->brokerage]))
            ->action($t('m.action'), $this->url)
            ->line($t('m.line2'))
            ->line($t('m.line3'));
    }
}
