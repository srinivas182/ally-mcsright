<?php

declare(strict_types=1);

namespace Modules\Core\Audit;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class WeeklySecurityDigestMail extends Notification
{
    use Queueable;

    /**
     * @param  array{sections: list<array{title: string, lines: list<string>}>, quiet: bool}  $digest
     */
    public function __construct(public readonly string $brokerage, public readonly array $digest) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)->subject(($this->digest['quiet'] ? 'All quiet: ' : 'Security digest: ').$this->brokerage.' this week');
        if ($this->digest['quiet']) {
            $mail->line('Nothing needed your attention this week.');
        }
        foreach ($this->digest['sections'] as $section) {
            $mail->line('**'.$section['title'].'**');
            foreach (array_slice($section['lines'], 0, 15) as $line) {
                $mail->line('• '.$line);
            }
        }

        return $mail->action('Open the audit log', url('/audit?view=activity&important=1'));
    }
}
