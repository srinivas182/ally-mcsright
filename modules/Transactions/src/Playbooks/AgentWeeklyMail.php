<?php

declare(strict_types=1);

namespace Modules\Transactions\Playbooks;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** The agent's Monday summary of their open deals. */
final class AgentWeeklyMail extends Notification
{
    use Queueable;

    private const HEADINGS = ['overdue' => 'Overdue', 'due' => 'Due in the next 7 days', 'mls' => 'Update the MLS', 'ready' => 'Emails ready to send', 'waiting' => 'Waiting on others'];

    /**
     * @param  array{due: list<string>, overdue: list<string>, waiting: list<string>, ready: list<string>, mls: list<string>}  $summary
     */
    public function __construct(public readonly array $summary) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)->subject('Your deals this week');
        foreach (self::HEADINGS as $key => $heading) {
            if ($this->summary[$key] === []) {
                continue;
            }
            $mail->line("**{$heading}**");
            foreach (array_slice($this->summary[$key], 0, 15) as $line) {
                $mail->line('• '.$line);
            }
        }

        return $mail->action('Open your deals', url('/deals'));
    }
}
