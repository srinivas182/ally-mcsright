<?php

declare(strict_types=1);

namespace Modules\Outreach;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Core\Audit\AuditLogger;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\TenantContext;
use Modules\Crm\Actions\Timeline;
use Modules\Crm\ContactVisibility;
use Modules\Crm\Inbox\Inbox;
use Modules\Crm\Leads\SpeedToLead;
use Modules\Crm\Models\ConsentEvent;
use Modules\Crm\Models\Contact;
use Modules\Crm\SmartLists;
use Modules\Listings\Search\ListingSearch;
use Modules\Listings\Search\MarketStats;
use stdClass;

/**
 * Monthly market report emails (MK3, D09, specs/crm/21). The agent picks an area and who gets it (past clients and
 * sphere, or any smart list); once a month at 8am brokerage time each person gets that area's real MLS numbers
 * (homes for sale, typical price, new and reduced listings, days on market) and three new listings, from their agent,
 * with a one-click stop. Never after an email opt-out; one area per person. Plain figures from the feed, no AI
 * cost, each with its source and date; Fair Housing: homes and prices only, never people.
 */
final class MarketReports
{
    /** "Past clients and sphere" as a ready-made choice. */
    public const PAST_AND_SPHERE = 'past-and-sphere';

    public const MAX_PER_START = 2000;

    public function __construct(private readonly TenantContext $tenants, private readonly MarketStats $stats, private readonly SmartLists $lists, private readonly ContactVisibility $visibility) {}

    /**
     * Who the agent can send to: past clients and sphere, then their smart lists.
     *
     * @return list<array{key: string, name: string}>
     */
    public function audiences(User $user): array
    {
        return [['key' => self::PAST_AND_SPHERE, 'name' => 'Past clients and sphere'],
            ...array_map(fn (array $l): array => ['key' => (string) $l['key'], 'name' => (string) $l['name']], $this->lists->listsFor($user))];
    }

    /** Starts the report for everyone in the audience who can get it. Returns how many started. */
    public function start(User $user, string $audience, string $place): int
    {
        if ($this->stats->forPlace($this->tenants->id(), $place) === null) {
            throw ValidationException::withMessages(['place' => 'Choose an area with enough homes for sale.']);
        }
        $filters = $audience === self::PAST_AND_SPHERE ? ['lifecycle' => ['past_client', 'sphere']] : $this->lists->filtersFor($audience, $user);
        if ($filters === null) {
            throw ValidationException::withMessages(['audience' => 'Choose who gets it.']);
        }
        $db = DB::connection(Connections::cell());
        $have = $db->table('outreach_market_reports')->where('tenant_id', $this->tenants->id())->pluck('contact_id')->all();
        $started = 0;
        $next = CarbonImmutable::today()->addDay()->toDateString(); // the first one goes out tomorrow morning
        foreach ($this->lists->apply($this->visibility->query($user), $filters)->whereNotNull('primary_email')->whereNotIn('id', $have ?: [0])->limit(self::MAX_PER_START)->get() as $c) {
            if (self::optedOut($c)) {
                continue;
            }
            $db->table('outreach_market_reports')->insert(['ulid' => (string) Str::ulid(), 'tenant_id' => $this->tenants->id(), 'contact_id' => $c->id, 'user_id' => $user->id,
                'place' => mb_substr($place, 0, 80), 'next_on' => $next, 'created_at' => now(), 'updated_at' => now()]);
            app(Timeline::class)->system($c, 'Monthly market report started ('.$place.').', $user->id);
            $started++;
        }
        app(AuditLogger::class)->record('outreach.market_report.started', metadata: ['place' => $place, 'people' => $started]);

        return $started;
    }

    /**
     * The agent's reports by area (or everyone's, for managers).
     *
     * @return list<array{place: string, name: string, people: int, stopped: int, sent: int, next_on: string|null}>
     */
    public function summary(?int $userId): array
    {
        $rows = DB::connection(Connections::cell())->table('outreach_market_reports')->where('tenant_id', $this->tenants->id())
            ->when($userId !== null, fn ($q) => $q->where('user_id', $userId))->selectRaw('place, count(*) as people, sum(case when unsubscribed_at is null then 0 else 1 end) as stopped, sum(sent_count) as sent, min(case when unsubscribed_at is null then next_on end) as next_on')
            ->groupBy('place')->orderBy('place')->get();

        return array_values($rows->map(fn ($r): array => ['place' => (string) $r->place, 'name' => (string) ($this->stats->forPlace($this->tenants->id(), (string) $r->place)['name'] ?? $r->place),
            'people' => (int) $r->people, 'stopped' => (int) $r->stopped, 'sent' => (int) $r->sent, 'next_on' => $r->next_on !== null ? substr((string) $r->next_on, 0, 10) : null])->all());
    }

    /** Sends every report that is due for this brokerage (run hourly; from 8am local). Returns the emails sent. */
    public function sendDue(): int
    {
        $tz = (string) ($this->tenants->current()->getAttribute('timezone') ?: 'America/Chicago');
        $local = CarbonImmutable::now()->setTimezone($tz);
        if ($local->hour < 8) {
            return 0;
        }
        $db = DB::connection(Connections::cell());
        $sent = 0;
        $built = [];
        foreach ($db->table('outreach_market_reports')->where('tenant_id', $this->tenants->id())->whereNull('unsubscribed_at')->whereDate('next_on', '<=', $local->toDateString())
            ->orderBy('id')->limit(1000)->get() as $row) {
            $db->table('outreach_market_reports')->where('id', $row->id)->update(['next_on' => $local->startOfDay()->addMonthNoOverflow()->toDateString(), 'updated_at' => now()]);
            $contact = Contact::query()->find((int) $row->contact_id);
            if ($contact === null || $contact->primary_email === null || self::optedOut($contact)) {
                continue;
            }
            $stats = $built[$row->place] ??= $this->stats->forPlace($this->tenants->id(), (string) $row->place) ?? [];
            if ($stats === []) {
                continue; // too few homes this month to publish
            }
            $homes = app(ListingSearch::class)->search($this->tenants->id(), ['q' => $stats['zip'] ?? ($stats['city'] ?? ''), 'sort' => 'newest', 'per_page' => 3])['results'];
            $agent = User::query()->find((int) $row->user_id);
            Mail::to((string) $contact->primary_email, $contact->name)->send(new MarketReportMail($contact->first_name, $stats, $homes, $local->format('F Y'),
                $this->tenants->current()->name, $agent?->name, $agent !== null ? (string) ($agent->getAttribute('phone') ?? '') : null, $this->stopUrl((string) $row->ulid),
                Inbox::replyAddress(app(Inbox::class)->conversationFor($contact))));
            $db->table('outreach_market_reports')->where('id', $row->id)->update(['last_sent_at' => now(), 'sent_count' => (int) $row->sent_count + 1]);
            app(Timeline::class)->system($contact, 'Sent the monthly market report for '.$stats['name'].'.');
            $sent++;
        }

        return $sent;
    }

    public function stop(string $ulid): ?stdClass
    {
        $db = DB::connection(Connections::cell());
        $row = $db->table('outreach_market_reports')->where('tenant_id', $this->tenants->id())->where('ulid', $ulid)->first();
        if ($row !== null && $row->unsubscribed_at === null) {
            $db->table('outreach_market_reports')->where('id', $row->id)->update(['unsubscribed_at' => now(), 'updated_at' => now()]);
            $contact = Contact::query()->find((int) $row->contact_id);
            if ($contact !== null) {
                app(Timeline::class)->system($contact, 'Turned off the monthly market report.');
            }
        }

        return $row;
    }

    public function stopUrl(string $ulid): string
    {
        return app(SpeedToLead::class)->link(URL::signedRoute('outreach.market-reports.stop', ['report' => $ulid], null, false));
    }

    private static function optedOut(Contact $c): bool
    {
        return ConsentEvent::query()->where('contact_id', $c->id)->where('channel', 'email')->latest('id')->value('action') === 'opt_out';
    }
}
