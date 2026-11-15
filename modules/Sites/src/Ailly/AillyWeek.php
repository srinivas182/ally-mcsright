<?php

declare(strict_types=1);

namespace Modules\Sites\Ailly;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** The website assistant's week, for owners. */
final class AillyWeek extends Notification
{
    use Queueable;

    /** @param list<string> $questions */
    public function __construct(public readonly int $chats, public readonly int $leads, public readonly int $calls, public readonly ?int $costPerLeadCents,
        public readonly array $questions) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject('Your website assistant this week: '.$this->leads.' '.($this->leads === 1 ? 'lead' : 'leads'))
            ->line(number_format($this->chats).' chats, '.number_format($this->leads).' leads and '.number_format($this->calls).' calls booked in the last 7 days.'
                .($this->costPerLeadCents !== null ? ' Cost per lead: $'.number_format($this->costPerLeadCents / 100, 2).' in credits.' : ''));
        if ($this->questions !== []) {
            $mail->line('Questions it couldn\'t answer (add an answer and it will use it from now on):');
            foreach ($this->questions as $q) {
                $mail->line('• '.$q);
            }
        }

        return $mail->action('See chats and add answers', url('/settings/ailly'));
    }
}
