<?php

declare(strict_types=1);

namespace Modules\Listings;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * To a brokerage owner: the MLS feed their website uses has fallen behind (specs/websites/16). Plain words, no
 * technical detail; the website already shows visitors a "listing updates are delayed" note until it catches up.
 */
final class FeedDelayedNotice extends Notification
{
    use Queueable;

    public function __construct(public readonly string $mls, public readonly ?int $minutes) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $behind = $this->minutes === null ? 'has not finished its first update yet' : 'is about '.self::plain($this->minutes).' behind';

        return (new MailMessage)->subject("Your {$this->mls} listings are running late")
            ->line("The {$this->mls} listing feed on your website {$behind}.")
            ->line('Your website tells visitors that updates are delayed, so nobody is misled. RightAlly support has been alerted and is on it; you do not need to do anything.')
            ->line('If it lasts more than a day, the MLS may have paused access. Check that your MLS licence is still active.')
            ->action('Open your MLS settings', url('/settings/mls'));
    }

    private static function plain(int $minutes): string
    {
        return $minutes < 120 ? $minutes.' minutes' : intdiv($minutes, 60).' hours';
    }
}
