<?php

declare(strict_types=1);

namespace Modules\Crm\Leads;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Email to an agent: a new lead is theirs (or offered to them), or a lead they did not answer moved on. */
final class LeadAlert extends Notification
{
    use Queueable;

    public function __construct(public readonly string $subject, public readonly string $line, public readonly string $url, public readonly string $action = 'Open the lead') {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)->subject($this->subject)->line($this->line)->action($this->action, $this->url);
    }
}
