<?php

declare(strict_types=1);

namespace Modules\Crm\Voice;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** VO1: tells the agent their call notes are ready (the AI call to a new lead, or their own recorded call). */
final class CallNotesMail extends Notification
{
    use Queueable;

    public function __construct(public readonly string $who, public readonly string $headline, public readonly string $summary, public readonly string $nextStep, public readonly string $url) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)->subject($this->headline.': '.$this->who)->line($this->summary);
        if ($this->nextStep !== '') {
            $mail->line('Next step: '.$this->nextStep);
        }

        return $mail->action('Open '.$this->who, $this->url);
    }
}
