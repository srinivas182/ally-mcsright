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
        public readonly string $websiteHost = '',
        public readonly bool $websiteLive = false,
    ) {
        $this->fromRightAlly = $this->kind === 'owner' && $this->inviterName === null;
    }

    /** EM5: a new brokerage's welcome comes from RightAlly itself (its look, signed by the founder). */
    public bool $fromRightAlly = false;

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $hello = trim($this->firstName) !== '' ? 'Hello '.trim($this->firstName).',' : 'Hello,';
        $where = $this->officeHost !== '' ? $this->officeHost : 'your back office';
        // A new brokerage's owner, straight after signing up: nobody invited them, they bought it.
        if ($this->fromRightAlly) {
            $signer = (array) config('core.welcome_signer');
            $first = trim($this->firstName) !== '' ? trim($this->firstName) : 'there';

            return (new MailMessage)
                ->subject("Welcome to the RightAlly family, {$first}")
                ->greeting($hello)
                ->line("Thank you for choosing RightAlly for **{$this->tenantName}**. It means a great deal that you trusted us with a business you have worked so hard to build, and we will look after it as if it were our own.")
                ->line('Your back office and your website are ready. The one thing to do now is set your password:')
                ->action('Set my password', $this->url)
                ->line("Your back office lives at **{$where}**. Bookmark it: it is where you and your agents will sign in every day.")
                ->lines($this->websiteLines())
                ->line('When you are in, these three steps make the biggest difference in your first week:')
                ->line('**1. Add your licence and your MLS board** under Settings, so your real listings replace the samples on your website.')
                ->line('**2. Bring your contacts and deals** with Switch to RightAlly. We do the heavy lifting.')
                ->line('**3. Invite your agents** under Team › People whenever you are ready.')
                ->line("{$first}, our whole team is here for you, from today and for as long as you are with us. If anything is unclear, simply reply to this email and a real person will answer.")
                ->line("For your security, the button above works once and expires on {$this->expiresOn}. If it has expired, reply to this email and we will send a new one.")
                ->salutation("Warm regards,  \n**".($signer['name'] ?? 'Sunil')."**  \n".($signer['title'] ?? 'CEO, RightAlly'));
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
            ->lines($this->websiteLines())
            ->line("This link works once and expires on {$this->expiresOn}. Didn't expect this email? You can safely ignore it.")
            ->salutation("See you inside,  \nThe {$this->tenantName} team");
    }

    /**
     * WS1: the website's address, kept apart from the back office's (owners mixed the two up).
     *
     * @return list<string>
     */
    private function websiteLines(): array
    {
        if ($this->websiteHost === '') {
            return [];
        }
        $url = 'https://'.$this->websiteHost;

        return $this->websiteLive
            ? ["Your website, the one your clients see, is at **[{$this->websiteHost}]({$url})**. It is live now, with sample homes until your MLS board is approved. This is the address to share with clients; the back office above is only for you and your team."]
            : ["Your website, the one your clients see, will be at **{$this->websiteHost}**. It goes live as soon as your licence is checked. The back office above is only for you and your team."];
    }
}
