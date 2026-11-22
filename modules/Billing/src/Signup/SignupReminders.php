<?php

declare(strict_types=1);

namespace Modules\Billing\Signup;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Modules\Core\Identity\Enums\UserKind;
use Modules\Core\Identity\Enums\UserStatus;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\Enums\TenantType;
use Modules\Core\Tenancy\Models\Tenant;
use Modules\Core\Tenancy\TenantContext;
use stdClass;

/**
 * SU1: people who filled in the signup form but did not finish paying. HQ sees them in HQ › Customers › Sign-ups.
 * They get at most two reminders, about an hour and a day later, each with a link that brings the form back filled
 * in, and a link to stop reminders. No reminder once they paid, chose to stop, or started again.
 */
final class SignupReminders
{
    /** Links in reminders work for two weeks. */
    public const LINK_DAYS = 14;

    /** @return list<stdClass> pending signups that are due a reminder now */
    public function due(?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();

        return array_values(DB::connection(Connections::control())->table('signups')
            ->whereIn('status', ['draft', 'pending'])->where('no_reminders', false)->where('updated_at', '>=', $now->subDays(7))
            ->where(fn ($q) => $q->where(fn ($q) => $q->where('reminders_sent', 0)->where('updated_at', '<=', $now->subHour()))
                ->orWhere(fn ($q) => $q->where('reminders_sent', 1)->where('created_at', '<=', $now->subDay())->where('reminded_at', '<=', $now->subHours(12))))
            ->orderBy('id')->limit(200)->get()
            ->reject(fn (stdClass $s): bool => $this->finished((string) $s->owner_email))->values()->all());
    }

    /** Sends the reminder for one signup (from the schedule, or HQ's "Send reminder"). False if there is nothing to remind. */
    public function send(stdClass $signup, bool $byHq = false): bool
    {
        if (! in_array($signup->status, ['draft', 'pending'], true) || (! $byHq && (bool) $signup->no_reminders) || $this->finished((string) $signup->owner_email)) {
            return false;
        }
        Notification::route('mail', (string) $signup->owner_email)->notify(new SignupReminderMail(
            (string) $signup->owner_name, (string) $signup->brokerage, self::resumeUrl($signup), self::stopUrl($signup), (int) $signup->reminders_sent + 1,
        ));
        // Not updated_at: that is when they last filled in a step, which decides when reminders go.
        DB::connection(Connections::control())->table('signups')->where('id', $signup->id)
            ->update(['reminders_sent' => (int) $signup->reminders_sent + 1, 'reminded_at' => now()]);
        if (! $byHq && (int) $signup->reminders_sent === 1) {
            $this->tellHq($signup); // SU2: still stuck a day later: HQ may want to call
        }

        return true;
    }

    /** The form again, filled in as they left it (signed, so nobody else can open it). */
    public static function resumeUrl(stdClass $signup): string
    {
        return URL::temporarySignedRoute($signup->type === 'solo' ? 'signup.agents' : 'signup.start', now()->addDays(self::LINK_DAYS), ['resume' => (string) $signup->ulid]);
    }

    public static function stopUrl(stdClass $signup): string
    {
        return URL::temporarySignedRoute('signup.reminders.stop', now()->addDays(60), ['signup' => (string) $signup->ulid]);
    }

    /** SU2: an email to HQ staff about someone stuck for a day, with where they stopped. */
    private function tellHq(stdClass $signup): void
    {
        $hq = Tenant::query()->where('type', TenantType::Hq)->first();
        if ($hq === null) {
            return;
        }
        $staff = app(TenantContext::class)->runAs($hq, fn () => User::query()->where('kind', UserKind::HqStaff)->where('status', UserStatus::Active)->get());
        Notification::send($staff, new SignupStuckMail((string) $signup->brokerage, (string) $signup->owner_name, (string) $signup->owner_email,
            $signup->status === 'pending' ? 'At the payment page' : 'Step '.(int) $signup->step.': '.(Signups::STEPS[(int) $signup->step] ?? '')));
    }

    /** They already have a RightAlly from another signup with the same email. */
    private function finished(string $email): bool
    {
        return DB::connection(Connections::control())->table('signups')->where('owner_email', mb_strtolower($email))->where('status', 'live')->exists();
    }
}
