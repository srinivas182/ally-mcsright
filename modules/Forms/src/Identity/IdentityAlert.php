<?php

declare(strict_types=1);

namespace Modules\Forms\Identity;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Tells the agent an identity or ownership check needs attention (possible seller-impersonation fraud). */
final class IdentityAlert extends Notification
{
    use Queueable;

    public function __construct(public readonly string $title, public readonly string $message, public readonly string $packet) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)->subject("Identity check needs attention: {$this->title}")->line($this->message)
            ->line('Do not proceed until you have confirmed who you are dealing with. Seller-impersonation fraud often starts with a listing agreement.')
            ->action('Open the forms', url('/forms/packets/'.$this->packet));
    }
}
