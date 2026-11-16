<?php

declare(strict_types=1);

namespace Modules\Billing\GoLive;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Monday, during the first five weeks: how the go-live plan is going and the next steps. */
final class GoLiveWeekMail extends Notification
{
    use Queueable;

    /** @param  list<string>  $next */
    public function __construct(public readonly int $day, public readonly int $done, public readonly int $total, public readonly array $next, public readonly string $url, public readonly ?string $coach) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)->subject("Your RightAlly go-live: day {$this->day}, {$this->done} of {$this->total} done")
            ->line("You're on day {$this->day} of your 30-day go-live plan, with {$this->done} of {$this->total} steps done.");
        if ($this->next !== []) {
            $mail->line('Next:');
            foreach ($this->next as $step) {
                $mail->line('• '.$step);
            }
        } else {
            $mail->line('Every step is done. Well done.');
        }
        if ($this->coach !== null) {
            $mail->line('Questions? Your coach, '.$this->coach.', is here to help.');
        }

        return $mail->action('Open my go-live plan', $this->url);
    }
}
