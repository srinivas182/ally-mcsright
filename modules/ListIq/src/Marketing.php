<?php

declare(strict_types=1);

namespace Modules\ListIq;

use App\Models\User;
use BaconQrCode\Renderer\GDLibRenderer;
use BaconQrCode\Writer;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Ads\Studio\BrandKits;
use Modules\Core\Audit\AuditLogger;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\TenantContext;
use Modules\Crm\Consent;
use Modules\Crm\Enums\Source;
use Modules\Crm\FollowUpPlans;
use Modules\Crm\Leads\LeadIntake;
use Modules\Crm\Models\Contact;
use Modules\Crm\Models\FollowUpPlan;
use Modules\Crm\Models\LeadSource;
use Modules\ListIq\Models\OpenHouse;
use Modules\ListIq\Models\Photo;
use Modules\ListIq\Models\Prep;
use Modules\Sites\SiteSetup;
use Throwable;

/**
 * The listing marketing centre (LI2, specs/listings/03): the listing's page on the brokerage website, QR codes for
 * signs, flyers and posts (each scan counted, each lead tagged), open houses with a sign-in kiosk that puts every
 * visitor into the open-house follow-up plan with their consent, the floor plan, the flyer, and the activity the
 * seller sees.
 *
 * Public marketing waits until the listing is in the MLS: under the MLS Clear Cooperation rule, a listing marketed to
 * the public must be in the MLS within one business day.
 */
final class Marketing
{
    public const KINDS = ['sign' => 'Yard sign', 'flyer' => 'Flyer', 'social' => 'Social post', 'mailer' => 'Postcard'];

    public function __construct(private readonly TenantContext $tenants) {}

    public function live(Prep $prep): bool
    {
        return $prep->in_mls && $this->host() !== null;
    }

    public function pageUrl(Prep $prep): ?string
    {
        $host = $this->host();

        return $host !== null ? 'https://'.$host.'/listing/'.$prep->ulid : null;
    }

    public function codeUrl(string $code): ?string
    {
        $host = $this->host();

        return $host !== null ? 'https://'.$host.'/q/'.$code : null;
    }

    /** A QR code for a sign, flyer, post or postcard. */
    public function addCode(Prep $prep, string $kind, ?string $label): string
    {
        if (! array_key_exists($kind, self::KINDS)) {
            throw ValidationException::withMessages(['kind' => 'Choose where the code goes.']);
        }
        do {
            $code = strtolower(Str::random(7));
        } while (DB::connection(Connections::cell())->table('listiq_codes')->where('code', $code)->exists() || preg_match('/[0o1il]/', $code) === 1);
        DB::connection(Connections::cell())->table('listiq_codes')->insert(['tenant_id' => $this->tenants->id(), 'listing_id' => $prep->id, 'code' => $code, 'kind' => $kind,
            'label' => mb_substr(trim((string) $label) ?: self::KINDS[$kind], 0, 80), 'created_at' => now(), 'updated_at' => now()]);

        return $code;
    }

    /**
     * @return list<array{code: string, kind: string, label: string, scans: int, leads: int, url: string|null}>
     */
    public function codes(Prep $prep): array
    {
        return array_values(DB::connection(Connections::cell())->table('listiq_codes')->where('tenant_id', $this->tenants->id())->where('listing_id', $prep->id)->orderBy('id')->get()
            ->map(fn (object $c): array => ['code' => (string) $c->code, 'kind' => (string) $c->kind, 'label' => (string) $c->label, 'scans' => (int) $c->scans, 'leads' => (int) $c->leads,
                'url' => $this->codeUrl((string) $c->code)])->all());
    }

    public function qrPng(string $url, int $size = 600): string
    {
        return (new Writer(new GDLibRenderer($size, 2)))->writeString($url);
    }

    /** A scan: counted, and where to send the person. */
    public function scan(string $code): ?Prep
    {
        $db = DB::connection(Connections::cell())->table('listiq_codes')->where('tenant_id', $this->tenants->id())->where('code', strtolower($code));
        $row = $db->first();
        $prep = $row !== null ? Prep::query()->find((int) $row->listing_id) : null;
        if ($row === null || $prep === null || ! $prep->in_mls) {
            return null;
        }
        DB::connection(Connections::cell())->table('listiq_codes')->where('id', $row->id)->update(['scans' => (int) $row->scans + 1, 'last_scan_at' => now()]);

        return $prep;
    }

    public function viewed(Prep $prep): void
    {
        $prep->newQuery()->whereKey($prep->id)->increment('page_views');
    }

    /** A lead from the listing's page; tagged with the code that brought them, if any. */
    public function countLead(Prep $prep, ?string $code): void
    {
        if ($code !== null && $code !== '') {
            DB::connection(Connections::cell())->table('listiq_codes')->where('tenant_id', $this->tenants->id())->where('listing_id', $prep->id)->where('code', strtolower($code))->increment('leads');
        }
    }

    public function openHouse(Prep $prep, string $starts, string $ends): OpenHouse
    {
        try {
            $from = CarbonImmutable::parse($starts, $this->tz());
            $to = CarbonImmutable::parse($ends, $this->tz());
        } catch (Throwable) {
            throw ValidationException::withMessages(['starts_at' => 'Choose the date and times.']);
        }
        if ($to->lte($from) || $from->diffInHours($to) > 12) {
            throw ValidationException::withMessages(['ends_at' => 'It should end after it starts, on the same day.']);
        }

        return OpenHouse::query()->create(['listing_id' => $prep->id, 'starts_at' => $from->utc(), 'ends_at' => $to->utc(), 'visitors' => []]);
    }

    /**
     * A visitor signs in on the kiosk: a lead for the listing agent, with consent recorded as they gave it, into the
     * open-house follow-up plan.
     *
     * @param  array<string, mixed>  $in  name, email, phone, agent (yes, no, looking), sms_consent
     */
    public function signIn(OpenHouse $oh, Prep $prep, array $in): void
    {
        if ($oh->ended_at !== null) {
            throw ValidationException::withMessages(['name' => 'This open house has ended.']);
        }
        $agent = in_array($in['agent'] ?? '', ['yes', 'no', 'looking'], true) ? (string) $in['agent'] : 'looking';
        $source = LeadSource::query()->firstOrCreate(['kind' => 'form', 'name' => 'Open house sign-in'],
            ['source' => Source::OpenHouse, 'token' => Str::random(40), 'consent_wording' => Consent::DEFAULT_SMS_WORDING, 'heading' => 'Open house sign-in', 'active' => true]);
        $owner = User::query()->findOrFail($prep->owner_user_id);
        $lead = app(LeadIntake::class)->receive($source, ['name' => $in['name'] ?? '', 'email' => $in['email'] ?? '', 'phone' => $in['phone'] ?? '', 'intent' => 'buy',
            'property' => $prep->street, 'zip' => $prep->zip, 'sms_consent' => (bool) ($in['sms_consent'] ?? false), 'source' => 'open_house',
            'message' => 'Signed in at the open house at '.$prep->street.'. Working with an agent: '.['yes' => 'yes', 'no' => 'no', 'looking' => 'not yet'][$agent].'.'], null, $owner);
        // Visitors who already have an agent aren't put into the plan.
        if ($agent !== 'yes' && $lead->contact_id !== null) {
            app(FollowUpPlans::class)->plansFor($owner);
            $plan = FollowUpPlan::query()->where('template_key', 'open_house')->where(fn ($q) => $q->where('shared', true)->orWhere('owner_user_id', $owner->id))->first();
            $contact = Contact::query()->find($lead->contact_id);
            if ($plan !== null && $contact !== null) {
                try {
                    app(FollowUpPlans::class)->enroll($contact, $plan, $owner);
                } catch (ValidationException) {
                    // already in a plan, or held: the lead still reaches the agent
                }
            }
        }
        $visitors = (array) $oh->visitors;
        $visitors[] = ['name' => mb_substr(trim((string) ($in['name'] ?? '')), 0, 120), 'contact_id' => $lead->contact_id, 'agent' => $agent, 'at' => now()->toIso8601String()];
        $oh->visitors = $visitors;
        $oh->save();
    }

    /** The open house is over: the agent gets the list of visitors, at once. */
    public function end(OpenHouse $oh, Prep $prep): void
    {
        if ($oh->ended_at !== null) {
            return;
        }
        $oh->ended_at = now();
        $oh->save();
        $owner = User::query()->find($prep->owner_user_id);
        $rows = array_map(fn (array $v): string => '<li>'.e($v['name']).' — '.['yes' => 'has an agent', 'no' => 'no agent', 'looking' => 'looking for an agent'][$v['agent']].'</li>', (array) $oh->visitors);
        if ($owner !== null) {
            Mail::to($owner->email)->send(new OpenHouseSummaryMail($prep->street, count((array) $oh->visitors), implode('', $rows)));
        }
        app(AuditLogger::class)->record('listiq.open_house.ended', $prep, metadata: ['visitors' => count((array) $oh->visitors)]);
    }

    public function floorPlan(Prep $prep, UploadedFile $file): void
    {
        $ext = strtolower($file->getClientOriginalExtension());
        if (! in_array($ext, ['pdf', 'jpg', 'jpeg', 'png'], true) || $file->getSize() > 20 * 1024 * 1024) {
            throw ValidationException::withMessages(['floor_plan' => 'A PDF, JPEG or PNG up to 20 MB, from your floor-plan app or partner.']);
        }
        $path = 'listiq/'.$this->tenants->id().'/'.$prep->ulid.'/floor-plan-'.Str::random(10).'.'.($ext === 'jpeg' ? 'jpg' : $ext);
        Storage::disk('public')->put($path, (string) $file->get());
        $prep->floor_plan_path = $path;
        $prep->save();
    }

    /** A one-page branded flyer (PDF): photos, facts, description, and a QR code to the listing's page. */
    public function flyer(Prep $prep): string
    {
        $owner = User::query()->findOrFail($prep->owner_user_id);
        $disk = Storage::disk('public');
        $photos = Photo::query()->where('listing_id', $prep->id)->where('use', true)->where('status', 'ready')->orderBy('position')->limit(4)->get()
            ->filter(fn (Photo $p): bool => $p->path !== null && $disk->exists($p->path))
            ->map(fn (Photo $p): array => ['src' => 'data:image/jpeg;base64,'.base64_encode((string) $disk->get((string) $p->path)), 'staged' => $p->kind === 'staged'])->values()->all();
        $code = collect($this->codes($prep))->firstWhere('kind', 'flyer')['code'] ?? $this->addCode($prep, 'flyer', 'Flyer');
        $url = $this->codeUrl($code);

        return Pdf::loadView('listiq::flyer', ['prep' => $prep, 'photos' => $photos, 'brand' => app(BrandKits::class)->forUser($owner), 'url' => $url,
            'qr' => $url !== null ? 'data:image/png;base64,'.base64_encode($this->qrPng($url, 300)) : null])->output();
    }

    /**
     * What's happened so far, for the agent and the seller.
     *
     * @return array{views: int, scans: int, code_leads: int, open_houses: int, visitors: int}
     */
    public function activity(Prep $prep): array
    {
        $codes = $this->codes($prep);
        $ohs = OpenHouse::query()->where('listing_id', $prep->id)->get();

        return ['views' => $prep->page_views, 'scans' => array_sum(array_column($codes, 'scans')), 'code_leads' => array_sum(array_column($codes, 'leads')),
            'open_houses' => $ohs->count(), 'visitors' => (int) $ohs->sum(fn (OpenHouse $o): int => count((array) $o->visitors))];
    }

    private function host(): ?string
    {
        try {
            return app(SiteSetup::class)->liveHost();
        } catch (Throwable) {
            return null;
        }
    }

    private function tz(): string
    {
        return $this->tenants->current()->timezone ?: 'America/Chicago';
    }
}
