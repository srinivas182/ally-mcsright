<?php

declare(strict_types=1);

namespace Modules\Network\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Referral network emails (AR1, specs/network/01): a new referral for you, an answer to yours, and updates on it. They
 * never include the client's details: those are only in RightAlly, after the referral is accepted.
 */
final class NetworkMail extends Notification
{
    use Queueable;

    /** @param  list<string>  $lines */
    public function __construct(public readonly string $kind, public readonly string $subject, public readonly array $lines, public readonly string $url) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)->subject($this->subject);
        foreach ($this->lines as $line) {
            $mail->line($line);
        }

        return $mail->action($this->kind === 'received' ? 'See the referral' : 'Open my referrals', $this->url);
    }
}
