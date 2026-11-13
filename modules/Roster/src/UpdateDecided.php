<?php

declare(strict_types=1);

namespace Modules\Roster;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class UpdateDecided extends Notification
{
    use Queueable;

    public function __construct(public readonly string $kind, public readonly bool $approved, public readonly ?string $note) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $what = match ($this->kind) {
            'licence' => 'licence', 'eo' => 'E&O', default => 'membership'
        };
        $mail = (new MailMessage)->subject($this->approved ? "Your {$what} update was approved" : "Your {$what} update needs another look");

        return $this->approved ? $mail->line("Your brokerage approved your {$what} update. It is now on your record.")
            : $mail->line("Your brokerage could not approve your {$what} update: {$this->note}")->action('Try again', url('/roster/me'));
    }
}
