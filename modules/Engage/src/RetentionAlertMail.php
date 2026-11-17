<?php

declare(strict_types=1);

namespace Modules\Engage;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Monday: agents who may be thinking of leaving, with the plain reasons and a suggested check-in (EN1, D21). */
final class RetentionAlertMail extends Notification
{
    use Queueable;

    /** @param list<array{name: string, reasons: list<string>, suggestion: string}> $agents */
    public function __construct(public readonly array $agents) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $m = (new MailMessage)->subject(count($this->agents).' agent(s) to check in with this week')
            ->line('These agents show signs they may be thinking of leaving. A short, personal check-in often helps.');
        foreach (array_slice($this->agents, 0, 10) as $a) {
            $m->line('**'.$a['name'].'**: '.implode(' ', $a['reasons']).' Suggested: '.$a['suggestion']);
        }

        return $m->action('See agent activity', url('/engage/activity'))->line('Agents never see this list.');
    }
}
