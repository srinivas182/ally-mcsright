<?php

declare(strict_types=1);

namespace Modules\Outreach;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Core\Audit\AuditLogger;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\TenantContext;
use Modules\Crm\Booking\Availability;
use Modules\Crm\Models\Contact;
use Modules\Sites\Models\Site;
use Modules\Sites\SiteSetup;
use Modules\Sites\Visitors\SiteLeads;

/**
 * Personal booking links (MK1, specs/crm/20): /meet/{name} on the brokerage's website shows the agent's open times
 * (their hours in CRM booking settings, minus tasks and their connected calendar) and books one as a CRM task, with
 * the person added as a lead assigned to that agent. Like Calendly, without a second app or a second calendar.
 */
final class BookingLinks
{
    public const KINDS = ['call' => 'Phone call', 'showing' => 'Home showing', 'listing' => 'Listing appointment'];

    public function __construct(private readonly TenantContext $tenants, private readonly Availability $availability) {}

    /**
     * @return array{slug: string, title: string, intro: string, kind: string, active: bool, bookings: int, url: string|null, hours_on: bool}
     */
    public function forUser(User $user): array
    {
        $row = DB::connection(Connections::cell())->table('outreach_booking_links')->where('tenant_id', $this->tenants->id())->where('user_id', $user->id)->first();
        $slug = $row !== null ? (string) $row->slug : $this->freeSlug(Str::slug($user->name) ?: 'agent', $user->id);
        $host = app(SiteSetup::class)->liveHost();

        return ['slug' => $slug, 'title' => $row !== null ? (string) $row->title : 'Book a time with '.$user->first_name, 'intro' => (string) ($row->intro ?? ''),
            'kind' => (string) ($row->kind ?? 'call'), 'active' => $row !== null && (bool) $row->active, 'bookings' => (int) ($row->bookings ?? 0),
            'url' => $row !== null && $row->active && $host !== null ? 'https://'.$host.'/meet/'.$slug : null, 'hours_on' => $this->availability->for($user->id)['enabled']];
    }

    /**
     * @param  array{slug: string, title: string, intro?: string|null, kind: string, active: bool}  $in
     */
    public function save(User $user, array $in): void
    {
        $slug = Str::slug($in['slug']);
        if ($slug === '' || mb_strlen($slug) > 60) {
            throw ValidationException::withMessages(['slug' => 'Use letters, numbers and dashes, for example jane-doe.']);
        }
        if (! isset(self::KINDS[$in['kind']])) {
            throw ValidationException::withMessages(['kind' => 'Choose what people book.']);
        }
        $db = DB::connection(Connections::cell());
        if ($db->table('outreach_booking_links')->where('tenant_id', $this->tenants->id())->where('slug', $slug)->where('user_id', '!=', $user->id)->exists()) {
            throw ValidationException::withMessages(['slug' => 'Someone else in your brokerage uses that link. Try another.']);
        }
        if ($in['active'] && ! $this->availability->for($user->id)['enabled']) {
            throw ValidationException::withMessages(['active' => 'Turn on your booking hours first (Settings, Booking hours), so people only see times you are free.']);
        }
        $db->table('outreach_booking_links')->updateOrInsert(['tenant_id' => $this->tenants->id(), 'user_id' => $user->id],
            ['slug' => $slug, 'title' => mb_substr(trim($in['title']), 0, 120) ?: 'Book a time', 'intro' => mb_substr(trim((string) ($in['intro'] ?? '')), 0, 500) ?: null,
                'kind' => $in['kind'], 'active' => $in['active'], 'updated_at' => now(), 'created_at' => now()]);
        app(AuditLogger::class)->record('outreach.booking_link_saved', metadata: ['slug' => $slug, 'active' => $in['active']]);
    }

    /**
     * @return array{user: User, slug: string, title: string, intro: string, kind: string}|null
     */
    public function bySlug(string $slug): ?array
    {
        $row = DB::connection(Connections::cell())->table('outreach_booking_links')->where('tenant_id', $this->tenants->id())->where('slug', $slug)->where('active', true)->first();
        $user = $row !== null ? User::query()->find($row->user_id) : null;
        if ($row === null || ! $user instanceof User || ! $user->isActive()) {
            return null;
        }

        return ['user' => $user, 'slug' => (string) $row->slug, 'title' => (string) $row->title, 'intro' => (string) ($row->intro ?? ''), 'kind' => (string) $row->kind];
    }

    /**
     * Open times grouped by day, in the brokerage's time zone.
     *
     * @return list<array{day: string, label: string, times: list<array{value: string, label: string}>}>
     */
    public function slots(User $agent, string $kind): array
    {
        $days = [];
        foreach ($this->availability->slots($agent, $kind, 13, 60) as $slot) {
            $key = $slot->toDateString();
            $days[$key] ??= ['day' => $key, 'label' => $slot->isoFormat('dddd, MMMM D'), 'times' => []];
            $days[$key]['times'][] = ['value' => $slot->utc()->format('Y-m-d\TH:i\Z'), 'label' => $slot->format('g:i A')];
        }

        return array_values($days);
    }

    /**
     * Books the time: the person becomes (or is matched to) a lead assigned to the agent, and the slot a task.
     *
     * @param  array{name: string, email: string, phone: string, notes: string, sms_consent: bool}  $in
     */
    public function book(Site $site, string $slug, string $slot, array $in, Request $request): CarbonImmutable
    {
        $link = $this->bySlug($slug) ?? throw ValidationException::withMessages(['slot' => 'This booking link is not available.']);
        try {
            $start = CarbonImmutable::createFromFormat('Y-m-d\TH:i\Z', $slot, 'UTC');
        } catch (\Throwable) {
            $start = null;
        }
        if (! $start instanceof CarbonImmutable) {
            throw ValidationException::withMessages(['slot' => 'Choose a time.']);
        }
        $source = SiteLeads::source('Booking link', 'Book a time');
        $lead = app(SiteLeads::class)->capture($site, $source, ['name' => $in['name'], 'email' => $in['email'], 'phone' => $in['phone'],
            'message' => mb_substr('Booked '.self::KINDS[$link['kind']].' for '.$start->setTimezone($this->availability->timezone())->format('D M j, g:i A').'. '.$in['notes'], 0, 4000),
            'sms_consent' => $in['sms_consent'], 'consent_text' => (string) $source->consent_wording], $request, 'booking', null, [], $link['user']);
        $contact = $lead->contact_id !== null ? Contact::query()->find($lead->contact_id) : null;
        $this->availability->book($link['user'], $contact, $start, $link['kind'], $in['notes']);
        DB::connection(Connections::cell())->table('outreach_booking_links')->where('tenant_id', $this->tenants->id())->where('slug', $slug)->increment('bookings');

        return $start->setTimezone($this->availability->timezone());
    }

    private function freeSlug(string $base, int $userId): string
    {
        $db = DB::connection(Connections::cell())->table('outreach_booking_links')->where('tenant_id', $this->tenants->id())->where('user_id', '!=', $userId);
        $slug = mb_substr($base, 0, 60);
        for ($i = 2; (clone $db)->where('slug', $slug)->exists(); $i++) {
            $slug = mb_substr($base, 0, 56).'-'.$i;
        }

        return $slug;
    }
}
