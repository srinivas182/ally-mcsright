<?php

declare(strict_types=1);

namespace Modules\Sites\Funnels;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Modules\Core\Audit\AuditLogger;
use Modules\Core\Identity\Enums\UserKind;
use Modules\Core\Tenancy\TenantContext;
use Modules\Crm\Actions\Timeline;
use Modules\Crm\Inbox\Inbox;
use Modules\Crm\Leads\SpeedToLead;
use Modules\Crm\Models\ConsentEvent;
use Modules\Crm\Models\Contact;
use Modules\Listings\Search\ListingSearch;
use Modules\Sites\Mail\HomeReportMail;
use Modules\Sites\Models\HomeReport;
use Modules\Sites\Models\SiteFunnel;
use Modules\Sites\Visitors\Valuation;

/**
 * The monthly home-value report (specs/grow/03, LG1b). A homeowner who asked a home-value funnel what their home is
 * worth gets, once a month at 8am brokerage time: the updated range, how it moved since last month, and a few homes
 * for sale nearby, from their agent, with a one-click stop. Never after an email opt-out. The range comes from homes
 * for sale (Valuation), so the email says the agent confirms it with recent sales.
 */
final class HomeReports
{
    public function __construct(private readonly TenantContext $tenants, private readonly Valuation $valuation, private readonly ListingSearch $search, private readonly Timeline $timeline) {}

    /** Starts (or keeps) the report for this home; the first one goes out a month from today. */
    public function start(Contact $contact, ?SiteFunnel $funnel, string $address, string $zip, ?int $beds, ?int $sqft, ?int $mid, ?int $agentId): ?HomeReport
    {
        if ($contact->primary_email === null) {
            return null;
        }
        $agentId ??= $contact->owner_user_id ?? User::query()->where('kind', UserKind::Owner)->orderBy('id')->value('id');
        if ($agentId === null) {
            return null;
        }
        $address = mb_substr(trim(strip_tags($address)), 0, 150);
        $existing = HomeReport::query()->where('contact_id', $contact->id)->where('address', $address)->first();
        if ($existing !== null) {
            return $existing;
        }
        $bought = SoldComps::purchase($contact->id, $address); // MK3: a past client's purchase with the brokerage
        $report = HomeReport::query()->create(['contact_id' => $contact->id, 'site_funnel_id' => $funnel?->id, 'user_id' => $agentId, 'address' => $address, 'zip' => $zip,
            'beds' => $beds, 'sqft' => $sqft, 'last_mid' => $mid, 'next_on' => CarbonImmutable::today()->addMonthNoOverflow()->toDateString(),
            'bought_price' => $bought['price'] ?? null, 'bought_on' => $bought['on'] ?? null]);
        $this->timeline->system($contact, 'Monthly home value report started for '.$address);

        return $report;
    }

    /** Sends every report that is due for this brokerage (run hourly). Returns the emails sent. */
    public function sendDue(): int
    {
        $tz = (string) ($this->tenants->current()->getAttribute('timezone') ?: 'America/Chicago');
        $local = CarbonImmutable::now()->setTimezone($tz);
        if ($local->hour < 8) {
            return 0;
        }
        $sent = 0;
        foreach (HomeReport::query()->whereNull('unsubscribed_at')->whereDate('next_on', '<=', $local->toDateString())->orderBy('id')->limit(500)->get() as $report) {
            $report->forceFill(['next_on' => $local->startOfDay()->addMonthNoOverflow()->toDateString()])->save();
            $contact = Contact::query()->find($report->contact_id);
            if ($contact === null || $contact->primary_email === null
                || ConsentEvent::query()->where('contact_id', $contact->id)->where('channel', 'email')->latest('id')->value('action') === 'opt_out') {
                continue;
            }
            $estimate = $this->valuation->estimate($this->tenants->id(), $report->zip, $report->beds, $report->sqft);
            $nearby = $this->search->search($this->tenants->id(), ['q' => $report->zip, 'sort' => 'newest', 'per_page' => 3])['results'];
            $sold = app(SoldComps::class)->near($this->tenants->id(), $report->zip, $report->beds, $report->sqft); // MK3
            if ($estimate === null && $nearby === [] && $sold['homes'] === []) {
                continue; // nothing worth sending this month
            }
            $change = $estimate !== null && $report->last_mid !== null && $report->last_mid > 0 ? round(($estimate['mid'] - $report->last_mid) * 100 / $report->last_mid, 1) : null;
            $agent = User::query()->find($report->user_id);
            Mail::to((string) $contact->primary_email, $contact->name)->send(new HomeReportMail($report->address, $contact->first_name, $estimate, $change, $nearby,
                $this->tenants->current()->name, $agent?->name, $agent?->phone, $this->stopUrl($report), Inbox::replyAddress(app(Inbox::class)->conversationFor($contact)),
                $this->dashboardUrl($report), $sold['homes'], $sold['range'],
                $report->bought_price !== null ? ['price' => $report->bought_price, 'year' => $report->bought_on?->format('Y'),
                    'change' => SoldComps::sinceBought($report->bought_price, isset($estimate['mid']) ? (int) $estimate['mid'] : null)] : null));
            $history = $report->history ?? [];
            if ($estimate !== null) {
                $history[] = ['on' => $local->toDateString(), 'mid' => (int) $estimate['mid']];
            }
            $report->forceFill(['last_mid' => $estimate['mid'] ?? $report->last_mid, 'last_sent_at' => CarbonImmutable::now(), 'sent_count' => $report->sent_count + 1,
                'history' => array_slice($history, -36)])->save();
            $this->timeline->system($contact, 'Sent the monthly home value report for '.$report->address.($estimate !== null
                ? ': $'.number_format($estimate['low']).' to $'.number_format($estimate['high']) : ''));
            $sent++;
        }

        return $sent;
    }

    public function unsubscribe(HomeReport $report): void
    {
        $report->forceFill(['unsubscribed_at' => CarbonImmutable::now()])->save();
        $contact = Contact::query()->find($report->contact_id);
        if ($contact !== null) {
            $this->timeline->system($contact, 'Turned off the monthly home value report for '.$report->address);
        }
        app(AuditLogger::class)->record('sites.home_report.stopped', $report, label: $report->address);
    }

    /** The homeowner's own dashboard (MK2): a private, signed link that does not expire. */
    public function dashboardUrl(HomeReport $report): string
    {
        return app(SpeedToLead::class)->link(URL::signedRoute('sites.home-reports.dashboard', ['report' => $report->ulid], null, false));
    }

    /**
     * Past clients with an address and email who are not getting the report yet, for the agent (or, for brokers, everyone's).
     *
     * @return list<Contact>
     */
    public function pastClientsWithout(?int $ownerId): array
    {
        $have = HomeReport::query()->pluck('contact_id')->all();

        return array_values(Contact::query()->where('lifecycle', 'past_client')->whereNotNull('primary_email')->whereNotNull('zip')->whereNotNull('address_line1')
            ->when($ownerId !== null, fn ($q) => $q->where('owner_user_id', $ownerId))->whereNotIn('id', $have ?: [0])->orderBy('last_name')->limit(500)->get()
            ->filter(fn (Contact $c): bool => ConsentEvent::query()->where('contact_id', $c->id)->where('channel', 'email')->latest('id')->value('action') !== 'opt_out')->all());
    }

    public function stopUrl(HomeReport $report): string
    {
        return app(SpeedToLead::class)->link(URL::signedRoute('sites.home-reports.stop', ['report' => $report->ulid], null, false));
    }
}
