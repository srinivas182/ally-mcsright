<?php

declare(strict_types=1);

namespace Modules\Core\Workflows;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** An approval an automation is waiting for (specs/core/06, W1). Urgent: it ignores quiet hours. */
final class WorkflowApprovalMail extends Notification
{
    use Queueable;

    public function __construct(public readonly string $question, public readonly string $about) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)->subject('Approval needed: '.$this->question)->line($this->about)->action('Approve or decline', url('/automations/approvals'));
    }
}
