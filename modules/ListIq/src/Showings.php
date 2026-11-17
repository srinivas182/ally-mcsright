<?php

declare(strict_types=1);

namespace Modules\ListIq;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Core\Audit\AuditLogger;
use Modules\Core\Deals\ListingActivity;
use Modules\Core\Tenancy\TenantContext;
use Modules\Crm\Leads\SpeedToLead;
use Modules\ListIq\Models\Prep;
use Modules\ListIq\Models\Showing;
use Throwable;

/**
 * Showings (SH1, specs/listings/04): buyers' agents ask for a time on the listing's page; the listing agent approves
 * (or the listing approves automatically within showing hours); access instructions go only to approved showings;
 * an hour after each showing the buyer's agent is asked three questions (with one reminder a day later); and the
 * seller sees it all in their deal portal, with the agent's note and plain advice when activity drops.
 */
final class Showings implements ListingActivity
{
    public const LENGTH_MINUTES = 30;

    public const HOURS = [8, 20]; // showings start between 8am and 8pm, the brokerage's time

    public function __construct(private readonly TenantContext $tenants) {}

    /**
     * @param  array<string, mixed>  $in  name, email, phone, brokerage, date (Y-m-d), time (H:i)
     */
    public function request(Prep $prep, array $in): Showing
    {
        $d = validator($in, ['name' => ['required', 'string', 'max:120'], 'email' => ['required', 'email', 'max:190'], 'phone' => ['nullable', 'string', 'max:20'],
            'brokerage' => ['nullable', 'string', 'max:120'], 'date' => ['required', 'date_format:Y-m-d'], 'time' => ['required', 'date_format:H:i']])->validate();
        $start = CarbonImmutable::parse($d['date'].' '.$d['time'], $this->tz());
        if ($start->lt(CarbonImmutable::now()->addMinutes(30)) || $start->gt(CarbonImmutable::now()->addDays(60))) {
            throw ValidationException::withMessages(['time' => 'Choose a time from half an hour from now, within the next 60 days.']);
        }
        if ($start->hour < self::HOURS[0] || $start->hour >= self::HOURS[1]) {
            throw ValidationException::withMessages(['time' => 'Showings start between 8am and 8pm.']);
        }
        $end = $start->addMinutes(self::LENGTH_MINUTES);
        $clash = Showing::query()->where('listing_id', $prep->id)->whereIn('status', ['approved', 'requested'])
            ->where('starts_at', '<', $end->utc())->where('ends_at', '>', $start->utc())->exists();
        if ($clash) {
            throw ValidationException::withMessages(['time' => 'That time is taken. Please choose another.']);
        }
        $showing = Showing::query()->create(['listing_id' => $prep->id, 'name' => mb_substr(strip_tags((string) $d['name']), 0, 120), 'email' => mb_strtolower((string) $d['email']),
            'phone' => $d['phone'] ?? null, 'brokerage' => isset($d['brokerage']) ? mb_substr(strip_tags((string) $d['brokerage']), 0, 120) : null,
            'starts_at' => $start->utc(), 'ends_at' => $end->utc(), 'status' => 'requested']);
        if ($prep->showing_mode === 'auto') {
            $this->approve($showing, null);
        } else {
            $agent = User::query()->find($prep->owner_user_id);
            if ($agent !== null) {
                Mail::to($agent->email)->send(new ShowingMail('requested', "Showing request: {$prep->street}, ".$this->when($showing),
                    [e($showing->name).($showing->brokerage ? ' ('.e($showing->brokerage).')' : '').' would like to show '.e($prep->street).' on '.e($this->when($showing)).'.'],
                    'Approve or decline', app(SpeedToLead::class)->link('/list-iq/'.$prep->ulid.'#showings')));
            }
        }

        return $showing;
    }

    /** Approved: the buyer's agent gets the time and, only now, the access instructions. */
    public function approve(Showing $showing, ?User $by): void
    {
        if ($showing->status !== 'requested') {
            return;
        }
        $prep = Prep::query()->findOrFail($showing->listing_id);
        $showing->fill(['status' => 'approved', 'decided_by' => $by?->id])->save();
        Mail::to($showing->email)->send(new ShowingMail('approved', "Showing confirmed: {$prep->street}, ".$this->when($showing), [
            'Your showing of '.e($prep->street).' is confirmed for '.e($this->when($showing)).'.',
            $prep->access !== null && trim($prep->access) !== '' ? '<strong>Access:</strong> '.nl2br(e($prep->access)) : 'The listing agent will send access details.',
            'Please leave the home as you found it. You\'ll get three quick feedback questions afterwards.',
        ]));
        app(AuditLogger::class)->record('listiq.showing.approved', $prep, metadata: ['showing' => $showing->ulid]);
    }

    public function decline(Showing $showing, User $by, ?string $reason): void
    {
        if (! in_array($showing->status, ['requested', 'approved'], true)) {
            return;
        }
        $prep = Prep::query()->findOrFail($showing->listing_id);
        $showing->fill(['status' => 'declined', 'decided_by' => $by->id, 'decline_reason' => $reason !== null ? mb_substr($reason, 0, 200) : null])->save();
        Mail::to($showing->email)->send(new ShowingMail('declined', "Showing not available: {$prep->street}",
            ['Sorry, '.e($this->when($showing)).' doesn\'t work for '.e($prep->street).'.'.($reason ? ' '.e($reason) : ''), 'Please request another time on the listing\'s page.']));
    }

    /**
     * Every 15 minutes: feedback requests an hour after each showing, and one reminder a day later.
     *
     * @return int emails sent
     */
    public function askFeedback(): int
    {
        $sent = 0;
        $now = CarbonImmutable::now();
        $due = Showing::query()->where('status', 'approved')->whereNull('feedback_at')->where('ends_at', '<=', $now->subHour())->where('feedback_asks', '<', 2)
            ->where(fn ($q) => $q->whereNull('feedback_asked_at')->orWhere('feedback_asked_at', '<=', $now->subDay()))->limit(200)->get();
        foreach ($due as $s) {
            $prep = Prep::query()->find($s->listing_id);
            if ($prep === null) {
                continue;
            }
            $token = Str::random(40);
            $s->fill(['feedback_hash' => hash('sha256', $token), 'feedback_asks' => $s->feedback_asks + 1, 'feedback_asked_at' => $now])->save();
            Mail::to($s->email)->send(new ShowingMail('feedback', ($s->feedback_asks > 1 ? 'Reminder: ' : '').'How did '.$prep->street.' show?',
                ['Thanks for showing '.e($prep->street).'. Three quick questions help the sellers (30 seconds).'], 'Give feedback',
                app(SpeedToLead::class)->link('/showing-feedback/'.$token)));
            $sent++;
        }

        return $sent;
    }

    public function byToken(string $token): ?Showing
    {
        return strlen($token) === 40 ? Showing::query()->where('feedback_hash', hash('sha256', $token))->first() : null;
    }

    /**
     * @param  array<string, mixed>  $in  interest (1-5), price (low, right, high), comments
     */
    public function feedback(Showing $showing, array $in): void
    {
        $d = validator($in, ['interest' => ['required', 'integer', 'between:1,5'], 'price' => ['required', 'in:low,right,high'], 'comments' => ['nullable', 'string', 'max:1000']])->validate();
        $showing->fill(['feedback' => ['interest' => (int) $d['interest'], 'price' => (string) $d['price'], 'comments' => isset($d['comments']) ? trim(strip_tags((string) $d['comments'])) : null],
            'feedback_at' => now()])->save();
    }

    /**
     * Feedback in numbers, for the agent and the seller.
     *
     * @return array{shown: int, answered: int, interest: float|null, price: array{low: int, right: int, high: int}, comments: list<string>}
     */
    public function summary(Prep $prep): array
    {
        $done = Showing::query()->where('listing_id', $prep->id)->where('status', 'approved')->where('ends_at', '<=', now())->get();
        $fb = $done->whereNotNull('feedback_at');
        $count = fn (string $k): int => $fb->filter(fn (Showing $s): bool => ($s->feedback['price'] ?? 'right') === $k)->count();
        $price = ['low' => $count('low'), 'right' => $count('right'), 'high' => $count('high')];

        return ['shown' => $done->count(), 'answered' => $fb->count(), 'interest' => $fb->isEmpty() ? null : round((float) $fb->avg(fn (Showing $s): int => (int) ($s->feedback['interest'] ?? 0)), 1),
            'price' => $price, 'comments' => array_values(array_map('strval', $fb->sortByDesc('feedback_at')->map(fn (Showing $s): ?string => $s->feedback['comments'] ?? null)->filter()->take(10)->values()->all()))];
    }

    /** Plain advice for the seller when activity drops (never a price: that's the agent's conversation). */
    public function advice(Prep $prep, string $locale): ?string
    {
        if (! $prep->in_mls || $prep->created_at->gt(now()->subDays(14))) {
            return null;
        }
        $recent = Showing::query()->where('listing_id', $prep->id)->where('status', 'approved')->where('starts_at', '>=', now()->subDays(14))->count();
        $s = $this->summary($prep);
        $es = $locale === 'es';
        if ($recent === 0) {
            return $es ? 'No ha habido visitas en las últimas dos semanas. Su agente le hablará sobre el precio, las fotos y la presentación de la casa.'
                : 'There have been no showings in the last two weeks. Your agent will talk with you about the price, the photos and how the home shows.';
        }
        if ($s['answered'] >= 3 && $s['price']['high'] > $s['answered'] / 2) {
            return $es ? 'La mayoría de los agentes que visitaron piensan que el precio es alto. Su agente le hablará sobre los próximos pasos.'
                : 'Most agents who showed the home think the price is high. Your agent will talk with you about next steps.';
        }

        return null;
    }

    public function forSeller(int $dealId, string $locale): ?array
    {
        $prep = Prep::query()->where('deal_id', $dealId)->latest('id')->first();
        if ($prep === null) {
            return null;
        }
        $s = $this->summary($prep);
        $a = app(Marketing::class)->activity($prep);
        $upcoming = Showing::query()->where('listing_id', $prep->id)->where('status', 'approved')->where('starts_at', '>', now())->orderBy('starts_at')->limit(10)->get()
            ->map(fn (Showing $x): string => $this->when($x))->values()->all();

        return ['upcoming' => $upcoming, 'shown' => $s['shown'], 'answered' => $s['answered'], 'interest' => $s['interest'], 'price' => $s['price'], 'comments' => $s['comments'],
            'views' => $a['views'], 'scans' => $a['scans'], 'visitors' => $a['visitors'], 'note' => $prep->seller_note, 'note_at' => $prep->seller_note_at?->toDateString(),
            'advice' => $this->advice($prep, $locale)];
    }

    /**
     * Listings in the MLS for a week or more with no showings in the last 7 days.
     *
     * @return list<Prep>
     */
    public function quiet(User $agent): array
    {
        return array_values(Prep::query()->where('owner_user_id', $agent->id)->where('in_mls', true)->where('created_at', '<=', now()->subDays(7))->get()
            ->filter(fn (Prep $p): bool => ! Showing::query()->where('listing_id', $p->id)->whereIn('status', ['approved', 'requested'])->where('starts_at', '>=', now()->subDays(7))->exists())
            ->all());
    }

    public function when(Showing $s): string
    {
        try {
            return $s->starts_at->setTimezone($this->tz())->format('D M j, g:i A');
        } catch (Throwable) {
            return $s->starts_at->toDateTimeString();
        }
    }

    private function tz(): string
    {
        return $this->tenants->current()->timezone ?: 'America/Chicago';
    }
}
