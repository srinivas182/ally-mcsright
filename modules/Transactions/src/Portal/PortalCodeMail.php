<?php

declare(strict_types=1);

namespace Modules\Transactions\Portal;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** The 6-digit sign-in code for the deal portal. No link in it: the code is typed on the page that asked for it. */
final class PortalCodeMail extends Notification
{
    use Queueable;

    public function __construct(public readonly string $lang, public readonly string $code, public readonly string $street) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)->subject(Copy::t($this->lang, 'mail.code_subject', ['code' => $this->code]))
            ->line(Copy::t($this->lang, 'mail.code_intro', ['street' => $this->street, 'code' => $this->code]))
            ->line(Copy::t($this->lang, 'fraud'));
    }
}
