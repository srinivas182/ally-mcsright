<?php

declare(strict_types=1);

namespace Modules\Sites\Ailly;

use App\Models\User;
use Carbon\CarbonImmutable;
use Modules\Crm\Booking\Availability;
use Modules\Crm\Inbox\Inbox;
use Modules\Crm\Models\Contact;
use Modules\Crm\Models\Task;
use Modules\Sites\Jobs\RemindBooking;

/**
 * Texts about a time booked through AIlly (specs/websites/14): a confirmation now and a reminder two hours before.
 * Both go through the inbox's automated send (written consent, quiet hours, opt-outs), so they appear in the agent's
 * inbox and replies come back there. Without consent nothing is texted (the confirmation email still goes).
 */
final class BookingTexts
{
    public static function confirm(Task $task, Contact $contact, string $assistant): void
    {
        $blocked = [];
        app(Inbox::class)->sendAutomated($contact, 'sms', self::text($task, $assistant, false), null, $assistant, $blocked);
        $at = CarbonImmutable::parse($task->due_at)->subHours(2);
        if ($at->gt(CarbonImmutable::now()->addMinutes(10))) {
            RemindBooking::dispatch($task->id, $assistant)->delay($at);
        }
    }

    /** The reminder, if the booking still stands at the same time. */
    public static function remind(int $taskId, string $assistant): void
    {
        $task = Task::query()->find($taskId);
        $contact = $task?->contact_id !== null ? Contact::query()->find($task->contact_id) : null;
        if ($task === null || $contact === null || $task->completed_at !== null || CarbonImmutable::parse($task->due_at)->lt(CarbonImmutable::now())) {
            return;
        }
        $blocked = [];
        app(Inbox::class)->sendAutomated($contact, 'sms', self::text($task, $assistant, true), null, $assistant, $blocked);
    }

    public static function text(Task $task, string $assistant, bool $reminder): string
    {
        $when = CarbonImmutable::parse($task->due_at)->setTimezone(app(Availability::class)->timezone())->format($reminder ? 'g:i A T' : 'D M j \a\t g:i A T');
        $user = User::query()->find($task->assigned_to);
        $agent = $user instanceof User ? $user->first_name : 'your agent';
        $what = match ($task->type) {
            'showing' => 'showing', 'call' => 'call', default => 'home value appointment'
        };

        return $reminder
            ? "Reminder from {$assistant}: your {$what} with {$agent} is today at {$when}. Reply here to change it. Reply STOP to opt out."
            : "{$assistant} (AI assistant): your {$what} with {$agent} is booked for {$when}. Reply here to change it. Reply STOP to opt out.";
    }
}
