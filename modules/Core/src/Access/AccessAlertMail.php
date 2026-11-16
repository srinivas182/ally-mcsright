<?php

declare(strict_types=1);

namespace Modules\Core\Access;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells owners the moment someone is given access to money, people, exports or roles (specs/core/03, R1).
 */
final class AccessAlertMail extends Notification
{
    use Queueable;

    /**
     * @param  list<string>  $what  plain-English permissions
     */
    public function __construct(public readonly string $headline, public readonly array $what, public readonly string $by) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)->subject('Access changed: '.$this->headline)
            ->line($this->headline.' (by '.$this->by.').')
            ->line('This gives access to:');
        foreach (array_slice($this->what, 0, 12) as $line) {
            $mail->line('• '.$line);
        }

        return $mail->line('If this was not expected, remove the role on the People page.')
            ->action('Review roles', url('/settings/roles'));
    }
}
