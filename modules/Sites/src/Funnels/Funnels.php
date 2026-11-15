<?php

declare(strict_types=1);

namespace Modules\Sites\Funnels;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Core\Audit\AuditLogger;
use Modules\Core\Identity\Enums\UserKind;
use Modules\Crm\FairHousing;
use Modules\Crm\Models\Contact;
use Modules\Crm\Models\Lead;
use Modules\Crm\Searches\SavedSearches;
use Modules\Sites\Models\Site;
use Modules\Sites\Models\SiteFunnel;
use Modules\Sites\Visitors\SiteLeads;
use Modules\Sites\Visitors\Valuation;

/**
 * Lead Generation Engine 1 (specs/grow/03): buyer and seller funnels. One page with one job at /go/{slug}, made in
 * three taps (template, area, publish), value first (homes or a price range on the page), then a short ask with the
 * texting consent box. Every lead goes through the normal intake (routing, speed to lead, AI first responder,
 * attribution) under the funnel's own source, so results reach commission.
 */
final class Funnels
{
    /** The templates: what each one is called, its default headline and intro (homes and places, never people). */
    public const KINDS = [
        'home_value' => ['label' => 'What\'s my home worth? (sellers)', 'headline' => 'What is your home in :area worth?',
            'intro' => 'Get a free price range from similar homes for sale nearby, then a local agent confirms it with recent sales. No cost, no obligation.', 'intent' => 'sell'],
        'new_listings' => ['label' => 'New listings in an area (buyers)', 'headline' => 'New homes for sale in :area',
            'intro' => 'See today\'s homes, then get new ones by email the day they come up. Free, and stop any time.', 'intent' => 'buy'],
        'first_home' => ['label' => 'First home (buyers)', 'headline' => 'Your first home in :area',
            'intro' => 'Homes at your budget, and the simple steps from pre-approval to keys. A local agent answers your questions.', 'intent' => 'buy'],
        'moving' => ['label' => 'Moving to an area (relocation)', 'headline' => 'Moving to :area?',
            'intro' => 'Homes for sale, prices and what the market is doing, with a local agent to help you from wherever you are now.', 'intent' => 'buy'],
    ];

    public function __construct(private readonly Valuation $valuation) {}

    /**
     * @param  array{kind: string, area: string, min_price?: int|null, max_price?: int|null, beds?: int|null, headline?: string|null, intro?: string|null, for_brokerage?: bool}  $in
     */
    public function create(Site $site, User $by, array $in): SiteFunnel
    {
        if (! isset(self::KINDS[$in['kind']])) {
            throw ValidationException::withMessages(['kind' => 'Choose a funnel.']);
        }
        $area = mb_substr(trim(strip_tags($in['area'])), 0, 80);
        if ($area === '') {
            throw ValidationException::withMessages(['area' => 'Name the area: a city, a neighbourhood or a ZIP code.']);
        }
        $template = self::KINDS[$in['kind']];
        $headline = trim(strip_tags((string) ($in['headline'] ?? ''))) ?: str_replace(':area', $area, $template['headline']);
        $intro = trim(strip_tags((string) ($in['intro'] ?? ''))) ?: $template['intro'];
        self::checkWords($headline.' '.$intro);
        $manager = in_array($by->kind, [UserKind::Owner, UserKind::Staff], true);
        $base = Str::slug(mb_substr($in['kind'] === 'home_value' ? 'home value '.$area : $headline, 0, 60)) ?: 'homes';
        $slug = $base;
        for ($i = 2; SiteFunnel::query()->where('site_id', $site->id)->where('slug', $slug)->exists(); $i++) {
            $slug = $base.'-'.$i;
        }
        $funnel = SiteFunnel::query()->create(['site_id' => $site->id, 'owner_user_id' => $manager && ($in['for_brokerage'] ?? true) ? null : $by->id, 'kind' => $in['kind'],
            'slug' => $slug, 'area' => $area, 'min_price' => self::money($in['min_price'] ?? null), 'max_price' => self::money($in['max_price'] ?? null),
            'beds' => isset($in['beds']) && (int) $in['beds'] > 0 ? min(10, (int) $in['beds']) : null, 'headline' => mb_substr($headline, 0, 120),
            'intro' => mb_substr($intro, 0, 400), 'status' => 'live', 'created_by' => $by->id]);
        SiteLeads::source($funnel->sourceName(), $funnel->headline);
        app(AuditLogger::class)->record('sites.funnel.created', $funnel, metadata: ['kind' => $funnel->kind, 'area' => $area], label: $funnel->headline);

        return $funnel;
    }

    /** @param  array{headline?: string|null, intro?: string|null, status?: string|null}  $in */
    public function update(SiteFunnel $funnel, array $in, User $by): void
    {
        $headline = trim(strip_tags((string) ($in['headline'] ?? $funnel->headline))) ?: $funnel->headline;
        $intro = trim(strip_tags((string) ($in['intro'] ?? $funnel->intro)));
        self::checkWords($headline.' '.$intro);
        $funnel->update(['headline' => mb_substr($headline, 0, 120), 'intro' => mb_substr($intro, 0, 400),
            'status' => in_array($in['status'] ?? null, ['live', 'paused'], true) ? $in['status'] : $funnel->status]);
        app(AuditLogger::class)->record('sites.funnel.updated', $funnel, metadata: ['status' => $funnel->status], label: $funnel->headline);
    }

    /**
     * The search a funnel shows and saves (its area as the place, its price and beds).
     *
     * @return array<string, mixed>
     */
    public static function filters(SiteFunnel $funnel, ?int $maxPrice = null): array
    {
        return array_filter(['q' => (string) $funnel->area, 'min_price' => $funnel->min_price, 'max_price' => $maxPrice ?? $funnel->max_price, 'beds' => $funnel->beds,
            'sort' => 'newest', 'per_page' => 12], fn ($v) => $v !== null);
    }

    /**
     * A visitor's answers become a lead (and, for buyers, a saved search with daily alerts; for sellers, the value
     * range on the lead). Returns the lead and what to show next.
     *
     * @param  array<string, mixed>  $in  name, email, phone, sms_consent, and the funnel's own questions
     * @return array{lead: Lead, estimate: array<string, mixed>|null}
     */
    public function capture(Site $site, SiteFunnel $funnel, array $in, Request $request): array
    {
        $template = self::KINDS[$funnel->kind];
        $source = SiteLeads::source($funnel->sourceName(), $funnel->headline);
        $budget = self::money($in['budget'] ?? null);
        $estimate = null;
        $notes = [];
        $data = ['funnel' => $funnel->ulid];
        $home = null;
        if ($funnel->kind === 'home_value') {
            $address = mb_substr(trim(strip_tags((string) ($in['address'] ?? ''))), 0, 150);
            $zip = preg_match('/^\d{5}$/', trim((string) ($in['zip'] ?? ''))) === 1 ? trim((string) $in['zip']) : null;
            if ($address === '' || $zip === null) {
                throw ValidationException::withMessages(['address' => 'Enter your street address and 5-digit ZIP code.']);
            }
            $beds = is_numeric($in['beds'] ?? null) ? max(0, min(20, (int) $in['beds'])) : null;
            $sqft = is_numeric($in['sqft'] ?? null) && (int) $in['sqft'] >= 300 ? min(50000, (int) $in['sqft']) : null;
            $estimate = $this->valuation->estimate($funnel->tenant_id, $zip, $beds, $sqft);
            $notes[] = 'Home value request for '.$address.' '.$zip.'. '.($estimate !== null
                ? 'Estimate: $'.number_format($estimate['low']).' to $'.number_format($estimate['high']).' from '.$estimate['comps'].' similar homes for sale.'
                : 'Not enough similar homes for sale to estimate; please prepare a price range.');
            $data += ['address' => $address.' '.$zip, 'mid' => $estimate['mid'] ?? null];
            $home = ['address' => $address, 'zip' => $zip, 'beds' => $beds, 'sqft' => $sqft];
            $in['property'] = $address.' '.$zip;
            $in['zip'] = $zip;
            $in['price'] = $estimate['mid'] ?? null;
        } else {
            if ($budget !== null) {
                $notes[] = 'Budget about $'.number_format($budget).'.';
            }
            foreach (['timeline' => 'Timeline', 'preapproved' => 'Pre-approved', 'move_date' => 'Moving'] as $k => $label) {
                if (is_string($in[$k] ?? null) && trim($in[$k]) !== '') {
                    $notes[] = $label.': '.mb_substr(trim(strip_tags($in[$k])), 0, 60).'.';
                }
            }
            $in['price'] = $budget ?? $funnel->max_price;
        }
        $owner = $funnel->owner_user_id !== null ? User::query()->find($funnel->owner_user_id) : null;
        $lead = app(SiteLeads::class)->capture($site, $source, [
            'name' => (string) ($in['name'] ?? ''), 'email' => (string) ($in['email'] ?? ''), 'phone' => (string) ($in['phone'] ?? ''),
            'intent' => $template['intent'], 'message' => mb_substr(trim($funnel->headline.'. '.implode(' ', $notes)), 0, 4000),
            'sms_consent' => (bool) ($in['sms_consent'] ?? false), 'referrer' => mb_substr((string) ($in['first_referrer'] ?? ''), 0, 500),
            'landing_url' => mb_substr((string) ($in['landing_url'] ?? ''), 0, 500), 'zip' => (string) ($in['zip'] ?? ''), 'price' => $in['price'],
            'property' => (string) ($in['property'] ?? ''),
        ] + array_filter(array_intersect_key($in, array_flip(['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'gclid'])), 'is_string'),
            $request, 'funnel', null, $data, $owner instanceof User ? $owner : null);
        $funnel->increment('leads');
        if ($home !== null && $lead->contact_id !== null && ($contact = Contact::query()->find($lead->contact_id)) !== null) {
            app(HomeReports::class)->start($contact, $funnel, $home['address'], $home['zip'], $home['beds'], $home['sqft'], $estimate['mid'] ?? null,
                $contact->owner_user_id ?? $funnel->owner_user_id);
        }
        if ($funnel->kind !== 'home_value') {
            $contact = $lead->contact_id !== null ? Contact::query()->find($lead->contact_id) : null;
            if ($contact !== null && $contact->primary_email !== null) {
                $agent = $contact->owner_user_id ?? $funnel->owner_user_id;
                try {
                    app(SavedSearches::class)->createForVisitor($contact, self::filters($funnel, $budget), $funnel->headline, $agent);
                } catch (ValidationException) {
                    // no usable place: the lead still stands
                }
            }
        }

        return ['lead' => $lead, 'estimate' => $estimate];
    }

    private static function checkWords(string $text): void
    {
        $flags = FairHousing::screen($text, 'websites', 'blocked', 'Funnel page');
        if ($flags !== []) {
            throw ValidationException::withMessages(['headline' => 'Describe homes and places, not who should live there (US Fair Housing). Please change: "'.implode('", "', $flags).'".']);
        }
    }

    private static function money(mixed $value): ?int
    {
        $clean = preg_replace('/[^0-9.]/', '', (string) (is_scalar($value) ? $value : ''));

        return is_numeric($clean) && (float) $clean >= 1000 ? min(100_000_000, (int) round((float) $clean)) : null;
    }
}
