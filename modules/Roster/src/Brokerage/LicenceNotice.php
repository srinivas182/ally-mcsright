<?php

declare(strict_types=1);

namespace Modules\Roster\Brokerage;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** LV2: a brokerage licence is about to expire, has lapsed, or the state's records changed. Emailed to the owners. */
final class LicenceNotice extends Notification
{
    use Queueable;

    public function __construct(public readonly string $what, public readonly string $next) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)->subject('Your brokerage licence: please check')->line($this->what)->line($this->next)->action('Open your licences', url('/settings/licences'));
    }
}
