<?php

declare(strict_types=1);

namespace Modules\Transactions\Portal;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * An email to a client or the other side, in their language, always ending with the wire-fraud warning
 * (specs/ai/02, §22). Sent to the address on the portal access, never to a user account.
 */
final class PortalMail extends Notification
{
    use Queueable;

    /**
     * @param  list<string>  $lines
     */
    public function __construct(public readonly string $lang, public readonly string $subject, public readonly array $lines, public readonly string $url,
        public readonly ?string $replyTo = null, public readonly string $actionKey = 'mail.open') {}

    /**
     * @return list<string>
     */
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
        $mail->action(Copy::t($this->lang, $this->actionKey), $this->url)->line(Copy::t($this->lang, 'fraud'));
        if ($this->replyTo !== null && $this->replyTo !== '') {
            $mail->replyTo($this->replyTo);
        }

        return $mail;
    }
}
