<?php

declare(strict_types=1);

namespace Modules\Forms;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class SignerDeclined extends Notification
{
    use Queueable;

    public function __construct(public readonly string $signer, public readonly string $title, public readonly string $reason, public readonly string $packet) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)->subject("{$this->signer} declined to sign {$this->title}")
            ->line("{$this->signer} declined: \"{$this->reason}\"")->action('Open the forms', url('/forms/packets/'.$this->packet));
    }
}
