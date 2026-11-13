<?php

declare(strict_types=1);

namespace Modules\Roster;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class LicenceProblem extends Notification
{
    use Queueable;

    public function __construct(public readonly string $agent, public readonly string $state, public readonly string $finding) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("Check {$this->agent}'s {$this->state} licence")
            ->line("{$this->agent}'s {$this->state} licence: {$this->finding}.")
            ->line("Confirm with the state. While this stands, the licence guard (when on) stops the agent's deals going under contract.")
            ->action('Open the roster', url('/roster'));
    }
}
