<?php

declare(strict_types=1);

namespace Modules\Core\Identity\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The invitation email. Carries the only copy of the raw token.
 *
 * EM3: written to the person by name. A brokerage owner who has just signed up gets a welcome with their first steps;
 * everyone else hears who invited them, to which brokerage, and what they will use it for.
 */
final class InvitationNotification extends Notification
{
    use Queueable;

    public function __construct(
        public readonly string $url,
        public readonly string $tenantName,
        public readonly ?string $inviterName,
        public readonly string $expiresOn,
        public readonly string $firstName = '',
        public readonly string $kind = '',
        public readonly string $officeHost = '',
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $hello = trim($this->firstName) !== '' ? 'Hi '.trim($this->firstName).',' : 'Hello,';
        $where = $this->officeHost !== '' ? $this->officeHost : 'your back office';
        // A new brokerage's owner, straight after signing up: nobody invited them, they bought it.
        if ($this->kind === 'owner' && $this->inviterName === null) {
            return (new MailMessage)
                ->subject("Welcome to RightAlly: {$this->tenantName} is ready")
                ->greeting($hello)
                ->line("Thank you for choosing RightAlly. **{$this->tenantName}** is set up and waiting for you.")
                ->line('Set your password to open your back office. After that, these are the best first steps:')
                ->line('**1. Add your licence** under Settings › Licences, so you can connect your MLS board.')
                ->line('**2. Check your website**: your name, phone number and photos under Settings › Website.')
                ->line('**3. Invite your agents** under People, so they can start using it today.')
                ->action('Set my password', $this->url)
                ->line("Your back office is at **{$where}**, so bookmark it once you're in.")
                ->line("For your security, this link works once and expires on {$this->expiresOn}. If it has expired, ask us for a new one by replying to this email.")
                ->salutation("Welcome aboard,  \nThe RightAlly team");
        }
        $role = match ($this->kind) {
            'owner' => 'an owner', 'staff' => 'a member of staff', 'assistant' => 'an assistant', default => 'an agent',
        };

        return (new MailMessage)
            ->subject(($this->inviterName ?? $this->tenantName)." invited you to join {$this->tenantName}")
            ->greeting($hello)
            ->line(($this->inviterName !== null ? "**{$this->inviterName}** has invited you" : 'You have been invited')." to join **{$this->tenantName}** as {$role}.")
            ->line("{$this->tenantName} uses RightAlly for its leads, deals, listings and paperwork, all in one place. Accept the invitation to set your password and get started.")
            ->action('Accept the invitation', $this->url)
            ->line("This link works once and expires on {$this->expiresOn}. Didn't expect this email? You can safely ignore it.")
            ->salutation("See you inside,  \nThe {$this->tenantName} team");
    }
}
