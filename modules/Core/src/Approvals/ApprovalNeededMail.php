<?php

declare(strict_types=1);

namespace Modules\Core\Approvals;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** A sensitive change is waiting for approval (FL1, specs/core/16). Urgent: it ignores quiet hours. */
final class ApprovalNeededMail extends Notification
{
    use Queueable;

    public function __construct(public readonly string $about, public readonly string $askedBy) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)->subject('Please approve: '.$this->about)
            ->line($this->askedBy.' asked for this change. It happens only after approval.')
            ->action('Approve or decline', url('/settings/approvals'));
    }
}
