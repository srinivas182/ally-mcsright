<?php

declare(strict_types=1);

namespace Modules\Transactions\DealIq;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** A message the agent approved, sent to someone on the deal; replies go to the agent (specs/ai/02, §1). */
final class PartyMessageMail extends Notification
{
    use Queueable;

    public function __construct(public readonly string $subject, public readonly string $body, public readonly string $agent, public readonly string $agentEmail,
        public readonly string $street) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)->subject($this->subject)->replyTo($this->agentEmail, $this->agent);
        foreach (preg_split('/\n{2,}/', $this->body) ?: [] as $para) {
            $mail->line(trim($para));
        }

        return $mail->salutation($this->agent);
    }
}
