<?php

declare(strict_types=1);

namespace Modules\Billing\Usage;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** The brokerage's website map paused for lack of credits: when, and how many visitors tried to use it since. */
final class MapsPaused extends Notification
{
    use Queueable;

    public function __construct(public readonly int $missed, public readonly string $since, public readonly bool $reminder) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $since = date('M j', (int) strtotime($this->since));

        return (new MailMessage)
            ->subject($this->reminder ? number_format($this->missed).' visitors couldn\'t use your website map' : 'Your website map is paused')
            ->line($this->reminder
                ? 'Your website map has been paused since '.$since.'. Since then, '.number_format($this->missed).' visitors tried to open it to look at homes.'
                : 'Your website has used this month\'s included map views and your brokerage is out of RightAlly Credits, so the map on your home search is paused.')
            ->line('Everything else on your website works. Visitors still see the list of homes, but not where they are.')
            ->action('Add credits or turn on auto top-up', url('/settings/credits#buy'))
            ->line('The map comes back the moment credits are added.');
    }
}
