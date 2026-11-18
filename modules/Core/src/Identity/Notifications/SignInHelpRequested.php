<?php

declare(strict_types=1);

namespace Modules\Core\Identity\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** SX3: someone in the brokerage lost the phone they sign in with and asked for help. */
final class SignInHelpRequested extends Notification
{
    use Queueable;

    public function __construct(public readonly string $name, public readonly string $email) {}

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
            ->subject("{$this->name} needs help signing in")
            ->line("{$this->name} ({$this->email}) says they lost the phone or key they use to sign in to RightAlly, and asked you for help.")
            ->line('First make sure it is really them: call them on the number you already have for them, or speak in person. Do not reply to this email to check.')
            ->line('Then open People and choose "Reset sign-in" next to their name. They will get an email to choose a new password and set up sign-in again.')
            ->action('Open People', url('/users'))
            ->line('If you did not expect this, choose "Not them" and nothing changes. The request expires in a day.');
    }
}
