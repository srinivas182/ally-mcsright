<?php

declare(strict_types=1);

namespace Modules\Transactions\DealIq;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** The upload link sent to the other side (specs/ai/02, §7.4). */
final class UploadRequestMail extends Notification
{
    use Queueable;

    public function __construct(public readonly string $name, public readonly string $what, public readonly string $street, public readonly string $agent,
        public readonly ?string $note, public readonly string $link) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)->subject("Please upload: {$this->what} for {$this->street}")
            ->greeting("Hello {$this->name},")
            ->line("{$this->agent} needs **{$this->what}** for {$this->street}.");
        if ($this->note !== null) {
            $mail->line($this->note);
        }

        return $mail->action('Upload it securely', $this->link)
            ->line('No account is needed. The link works once, for 14 days. We will never ask you to send money or change wiring instructions by email.');
    }
}
