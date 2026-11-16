<?php

declare(strict_types=1);

namespace Modules\Core\Workflows;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** An alert an automation sends to people in the brokerage (specs/core/06, W1); it lands in their notification centre too. */
final class WorkflowAlertMail extends Notification
{
    use Queueable;

    public function __construct(public readonly string $title, public readonly string $text, public readonly ?string $path = null, public readonly bool $urgent = false) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)->subject($this->title)->line($this->text);

        return $this->path !== null ? $mail->action('Open in RightAlly', url($this->path)) : $mail;
    }
}
