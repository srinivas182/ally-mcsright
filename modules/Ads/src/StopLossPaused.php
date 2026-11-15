<?php

declare(strict_types=1);

namespace Modules\Ads;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Tells the person their ad was paused by the stop-loss, and why (specs/ads/02). */
final class StopLossPaused extends Notification
{
    use Queueable;

    public function __construct(public readonly string $listing, public readonly string $why) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)->subject('Your ad for '.$this->listing.' was paused')
            ->line($this->why)
            ->line('Nothing more is spent until you choose to run it again. Try a new photo or headline, or a bigger area.')
            ->action('See your ads', url('/ads'));
    }
}
