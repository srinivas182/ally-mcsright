<?php

declare(strict_types=1);

namespace Modules\Transactions\Policies;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** A policy document was added or changed: what changed, and a link to acknowledge it. */
final class PolicyChangedMail extends Notification
{
    use Queueable;

    public function __construct(public readonly string $title, public readonly string $summary) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)->subject('Policy update: '.$this->title)->line('Your brokerage updated "'.$this->title.'". What changed:')->line($this->summary)
            ->action('Read and acknowledge', url('/policies'));
    }
}
