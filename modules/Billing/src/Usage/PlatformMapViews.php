<?php

declare(strict_types=1);

namespace Modules\Billing\Usage;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** HQ: all websites together passed the monthly map-view alert line (so the Mapbox bill may grow). */
final class PlatformMapViews extends Notification
{
    use Queueable;

    public function __construct(public readonly int $views, public readonly int $line) {}

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
            ->subject('Website maps passed '.number_format($this->line).' views this month')
            ->line('All brokerages\' websites together have used '.number_format($this->views).' map views this month. Nothing has paused.')
            ->line('Views beyond each brokerage\'s allowance are paid from its credits. Check the brokerages RightAlly covers and each package\'s included map views.')
            ->action('Open RightAlly credits', url('/hq/credits'));
    }
}
