<?php

declare(strict_types=1);

namespace Modules\Transactions\Mls;

use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** The MLS still shows the old status for a listing deal. */
final class MlsStatusMail extends Notification
{
    use Queueable;

    public function __construct(public readonly string $street, public readonly string $mls, public readonly string $expected, public readonly string $seen,
        public readonly CarbonImmutable $due, public readonly string $path) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)->subject("Update the MLS: {$this->street} should show {$this->expected}")
            ->line("The MLS still shows {$this->seen} for {$this->street} (MLS #{$this->mls}). Your MLS expects {$this->expected} by ".$this->due->format('l, F j').'; late status changes can be fined.')
            ->line('Once the MLS shows the new status, this reminder clears by itself. If it is still showing the old status after that day, your broker is told.')
            ->action('Open the deal', url($this->path));
    }
}
