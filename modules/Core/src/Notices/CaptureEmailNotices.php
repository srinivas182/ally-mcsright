<?php

declare(strict_types=1);

namespace Modules\Core\Notices;

use App\Models\User;
use Illuminate\Notifications\Events\NotificationSending;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * Every email alert to a person also lands in their notification centre, and their preferences decide whether the
 * email goes now, waits for the end of quiet hours or the daily digest, or not at all (specs/core/10, N1). Essential
 * messages always go and are not copied into the inbox.
 */
final class CaptureEmailNotices
{
    public function __construct(private readonly NoticeTypes $types, private readonly Notices $notices) {}

    public function handle(NotificationSending $event): bool
    {
        if ($event->channel !== 'mail' || ! $event->notifiable instanceof User || $this->types->essential($event->notification)) {
            return true;
        }
        $user = $event->notifiable;
        $type = $this->types->for($event->notification);
        $title = $type['label'];
        $body = null;
        $url = null;
        if (method_exists($event->notification, 'toMail')) {
            try {
                $mail = $event->notification->toMail($user);
                if ($mail instanceof MailMessage) {
                    $title = filled($mail->subject) ? (string) $mail->subject : $title;
                    $body = isset($mail->introLines[0]) && is_string($mail->introLines[0]) ? strip_tags(str_replace('**', '', $mail->introLines[0])) : null;
                    $url = $mail->actionUrl !== null ? (string) (parse_url($mail->actionUrl, PHP_URL_PATH) ?? '') : null;
                }
            } catch (\Throwable) {
                // A copy for the inbox is never worth failing the email.
            }
        }
        $pref = $this->notices->pref($user, $type['key']);
        if (! $pref['email']) {
            $this->notices->add($user, $type['key'], $title, $body, $url, $type['urgent']);

            return false;
        }
        $hold = ! $type['urgent'] && ($this->notices->settings($user)['digest'] || $this->notices->quiet($user));
        $this->notices->add($user, $type['key'], $title, $body, $url, $type['urgent'], emailPending: $hold);

        return ! $hold;
    }
}
