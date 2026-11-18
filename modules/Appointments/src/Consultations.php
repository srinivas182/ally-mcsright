<?php

declare(strict_types=1);

namespace Modules\Appointments;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Appointments\Mail\GuideMail;
use Modules\Core\Audit\AuditLogger;
use Modules\Core\Deals\BuyerAgreementGuard;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\TenantContext;
use Modules\Crm\Actions\Timeline;
use Modules\Crm\ContactVisibility;
use Modules\Crm\CrmAccess;
use Modules\Crm\Models\Contact;
use Modules\Crm\Models\Lead;
use Modules\Pricing\Calculators;
use Modules\Pricing\Models\Cma;
use Modules\Transactions\Deals;
use Modules\Transactions\Models\Deal;

/**
 * Guided appointments (AP1, specs/listings/05). A listing appointment walks the agent and seller through the home,
 * the price (the CMA), what the seller nets, the marketing plan and the listing terms, then creates the listing deal
 * and emails the seller a summary. A buyer consultation covers what they need and the written buyer agreement the
 * 2024 NAR settlement requires before touring: a specific amount or rate of compensation (never open-ended), the
 * term, and whether it is exclusive. Until it is signed, scheduling a showing for that buyer is stopped.
 */
final class Consultations implements BuyerAgreementGuard
{
    public const STEPS = [
        'listing' => ['home' => 'The home', 'price' => 'The price', 'net' => 'What you walk away with', 'marketing' => 'How we\'ll market it', 'terms' => 'The listing agreement', 'finish' => 'Next steps'],
        'buyer' => ['needs' => 'What you\'re looking for', 'money' => 'Budget and financing', 'agreement' => 'How I\'m paid (buyer agreement)', 'sign' => 'Signing', 'finish' => 'Next steps'],
    ];

    public const MARKETING = ['photos' => 'Professional photos and floor plan', 'staging' => 'Staging advice (virtual staging where it helps)', 'mls' => 'On the MLS and every major portal',
        'website' => 'Its own page on our website with a QR code', 'open_house' => 'Open house with digital sign-in', 'social' => 'Social posts and ads', 'feedback' => 'Showing feedback sent to you after every visit'];

    public function __construct(private readonly TenantContext $tenants, private readonly ContactVisibility $visibility) {}

    public function start(string $kind, Contact $contact, User $by): string
    {
        if (! isset(self::STEPS[$kind])) {
            throw ValidationException::withMessages(['kind' => 'Choose a listing appointment or a buyer consultation.']);
        }
        abort_unless($this->visibility->canSee($by, $contact), 403);
        $ulid = (string) Str::ulid();
        $answers = $kind === 'listing'
            ? ['home' => ['street' => (string) ($contact->address_line1 ?? ''), 'city' => (string) ($contact->city ?? ''), 'state' => (string) ($contact->state ?? ''), 'zip' => (string) ($contact->zip ?? '')],
                'marketing' => array_fill_keys(array_keys(self::MARKETING), true), 'terms' => ['listing_pct' => (float) config('pricing.listing_commission_pct', 3.0), 'buyer_pct' => null, 'months' => 6]]
            : ['agreement' => ['comp_kind' => 'percent', 'comp_value' => (float) config('pricing.buyer_agent_pct', 2.5), 'months' => 3, 'exclusive' => true, 'area' => '']];
        DB::connection(Connections::cell())->table('appt_consultations')->insert(['ulid' => $ulid, 'tenant_id' => $this->tenants->id(), 'kind' => $kind, 'contact_id' => $contact->id,
            'user_id' => $by->id, 'status' => 'in_progress', 'answers' => (string) json_encode($answers), 'created_at' => now(), 'updated_at' => now()]);
        app(Timeline::class)->system($contact, ($kind === 'listing' ? 'Listing appointment' : 'Buyer consultation').' started.', $by->id);

        return $ulid;
    }

    /**
     * @return array<string, mixed>
     */
    public function show(string $ulid, User $user): array
    {
        $row = $this->row($ulid, $user);
        $contact = Contact::query()->whereKey((int) $row->contact_id)->first();
        $answers = (array) json_decode((string) $row->answers, true);
        $out = ['ulid' => $row->ulid, 'kind' => $row->kind, 'status' => $row->status, 'steps' => self::STEPS[$row->kind], 'answers' => $answers,
            'contact' => $contact !== null ? ['ulid' => $contact->ulid, 'name' => trim($contact->first_name.' '.$contact->last_name), 'email' => $contact->primary_email] : null,
            'agreement' => ['status' => $row->agreement_status, 'signed_on' => $row->agreement_signed_on, 'ends_on' => $row->agreement_ends_on, 'has_file' => $row->agreement_file !== null],
            'deal' => $row->deal_id !== null ? Deal::query()->whereKey($row->deal_id)->value('ulid') : null, 'guide_sent_at' => $row->guide_sent_at];
        if ($row->kind === 'listing') {
            $out['marketing_options'] = self::MARKETING;
            $out['cmas'] = Cma::query()->where('contact_id', $row->contact_id)->orderByDesc('id')->limit(10)->get()
                ->map(fn (Cma $c): array => ['ulid' => $c->ulid, 'title' => $c->title, 'low_cents' => $c->result['low_cents'] ?? null, 'likely_cents' => $c->result['likely_cents'] ?? null,
                    'high_cents' => $c->result['high_cents'] ?? null])->values()->all();
            $price = (int) round((float) ($answers['price']['list_price'] ?? 0) * 100);
            $out['net'] = $price > 0 ? Calculators::net($price, ['payoff_cents' => (int) round((float) ($answers['net']['payoff'] ?? 0) * 100),
                'listing_pct' => (float) ($answers['terms']['listing_pct'] ?? 3.0), 'buyer_pct' => (float) ($answers['terms']['buyer_pct'] ?? 0),
                'closing_pct' => (float) (config('pricing.seller_closing_pct.'.strtoupper((string) ($answers['home']['state'] ?? ''))) ?? config('pricing.seller_closing_pct.default', 1.2))]) : null;
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $values
     */
    public function save(string $ulid, string $step, array $values, User $user): void
    {
        $row = $this->row($ulid, $user);
        if (! isset(self::STEPS[$row->kind][$step])) {
            throw ValidationException::withMessages(['step' => 'Unknown step.']);
        }
        $answers = (array) json_decode((string) $row->answers, true);
        $clean = [];
        foreach ($values as $k => $v) {
            if (preg_match('/^[a-z_]{1,30}$/', (string) $k) === 1) {
                $clean[$k] = is_bool($v) || is_numeric($v) ? $v : (is_array($v) ? array_slice($v, 0, 20) : mb_substr(trim((string) $v), 0, 1000));
            }
        }
        if ($row->kind === 'buyer' && $step === 'agreement') {
            self::checkAgreement($clean);
        }
        $answers[$step] = $clean + (array) ($answers[$step] ?? []);
        DB::connection(Connections::cell())->table('appt_consultations')->where('id', $row->id)->update(['answers' => (string) json_encode($answers), 'updated_at' => now()]);
    }

    /** The buyer agreement is signed (on paper or elsewhere): record it, with the signed copy when there is one. */
    public function signed(string $ulid, string $signedOn, ?UploadedFile $file, User $user): void
    {
        $row = $this->row($ulid, $user);
        if ($row->kind !== 'buyer') {
            abort(404);
        }
        $answers = (array) json_decode((string) $row->answers, true);
        self::checkAgreement((array) ($answers['agreement'] ?? []));
        $on = CarbonImmutable::parse($signedOn);
        $path = null;
        if ($file !== null) {
            if ($file->getMimeType() !== 'application/pdf') {
                throw ValidationException::withMessages(['file' => 'Attach the signed agreement as a PDF.']);
            }
            $path = 'appointments/'.$this->tenants->id().'/'.$row->ulid.'-agreement.pdf';
            Storage::disk('local')->put($path, (string) file_get_contents($file->getRealPath()));
        }
        $months = max(1, min(24, (int) ($answers['agreement']['months'] ?? 3)));
        DB::connection(Connections::cell())->table('appt_consultations')->where('id', $row->id)->update(['agreement_status' => 'signed', 'agreement_signed_on' => $on->toDateString(),
            'agreement_ends_on' => $on->addMonthsNoOverflow($months)->toDateString(), 'agreement_file' => $path ?? $row->agreement_file, 'updated_at' => now()]);
        $contact = Contact::query()->whereKey((int) $row->contact_id)->first();
        if ($contact !== null) {
            app(Timeline::class)->system($contact, 'Buyer agreement signed ('.self::compensation((array) $answers['agreement']).', until '.$on->addMonthsNoOverflow($months)->format('M j, Y').').', $user->id);
        }
        app(AuditLogger::class)->record('appointments.buyer_agreement_signed', metadata: ['consultation' => $row->ulid], severity: 'notice');
    }

    /** Finishes: a listing becomes a listing deal; either way the person gets their guide by email. */
    public function finish(string $ulid, bool $sendGuide, User $user): ?string
    {
        $row = $this->row($ulid, $user);
        $answers = (array) json_decode((string) $row->answers, true);
        $contact = Contact::query()->whereKey((int) $row->contact_id)->firstOrFail();
        $dealUlid = null;
        if ($row->kind === 'listing' && $row->deal_id === null) {
            $home = (array) ($answers['home'] ?? []);
            if (trim((string) ($home['street'] ?? '')) === '' || preg_match('/^[A-Za-z]{2}$/', (string) ($home['state'] ?? '')) !== 1) {
                throw ValidationException::withMessages(['home' => 'Add the street and state of the home first.']);
            }
            $deal = app(Deals::class)->create(['type' => 'listing', 'street' => (string) $home['street'], 'city' => ($home['city'] ?? '') ?: null, 'state_code' => (string) $home['state'],
                'postal_code' => ($home['zip'] ?? '') ?: null, 'price_cents' => (int) round((float) ($answers['price']['list_price'] ?? 0) * 100)], $user, $user, $contact);
            $deal->forceFill(['listing_expires_on' => CarbonImmutable::today()->addMonthsNoOverflow(max(1, min(24, (int) ($answers['terms']['months'] ?? 6))))->toDateString()])->save();
            DB::connection(Connections::cell())->table('appt_consultations')->where('id', $row->id)->update(['deal_id' => $deal->id]);
            $dealUlid = $deal->ulid;
        }
        if ($sendGuide && $contact->primary_email !== null) {
            Mail::to((string) $contact->primary_email, $contact->name)->send(new GuideMail($row->kind, $contact->first_name, $answers, $user->name, (string) ($user->getAttribute('phone') ?? ''),
                $this->tenants->current()->name, $row->kind === 'buyer' ? $row->agreement_status === 'signed' : null));
            DB::connection(Connections::cell())->table('appt_consultations')->where('id', $row->id)->update(['guide_sent_at' => now()]);
            app(Timeline::class)->system($contact, 'Sent the '.($row->kind === 'listing' ? 'seller' : 'buyer').' guide by email.', $user->id);
        }
        DB::connection(Connections::cell())->table('appt_consultations')->where('id', $row->id)->update(['status' => 'done', 'updated_at' => now()]);

        return $dealUlid;
    }

    /**
     * @return list<array{ulid: string, kind: string, contact: string, status: string, agreement_status: string, updated_at: string}>
     */
    public function mine(User $user): array
    {
        $rows = DB::connection(Connections::cell())->table('appt_consultations')->where('tenant_id', $this->tenants->id())->where('user_id', $user->id)->orderByDesc('updated_at')->limit(50)->get();
        $names = Contact::query()->whereIn('id', $rows->pluck('contact_id')->all() ?: [0])->get()->keyBy('id');

        return array_values($rows->map(fn ($r): array => ['ulid' => (string) $r->ulid, 'kind' => (string) $r->kind,
            'contact' => ($c = $names->get($r->contact_id)) instanceof Contact ? trim($c->first_name.' '.$c->last_name) : '—', 'status' => (string) $r->status,
            'agreement_status' => (string) $r->agreement_status, 'updated_at' => substr((string) $r->updated_at, 0, 10)])->all());
    }

    /** The "agreement needed" guard for showings (Core contract). */
    public function missing(int $contactId): ?string
    {
        $db = DB::connection(Connections::cell());
        $today = CarbonImmutable::today()->toDateString();
        $signed = $db->table('appt_consultations')->where('tenant_id', $this->tenants->id())->where('contact_id', $contactId)->where('kind', 'buyer')
            ->where('agreement_status', 'signed')->where(fn ($q) => $q->whereNull('agreement_ends_on')->orWhere('agreement_ends_on', '>=', $today))->exists();
        if ($signed) {
            return null;
        }
        $buyer = $db->table('appt_consultations')->where('tenant_id', $this->tenants->id())->where('contact_id', $contactId)->where('kind', 'buyer')->exists()
            || Lead::query()->where('contact_id', $contactId)->where('intent', 'buy')->exists();

        return $buyer ? 'A written buyer agreement is needed before touring a home with this buyer (NAR settlement). Do the buyer consultation and record the signed agreement first.' : null;
    }

    public function file(string $ulid, User $user): ?string
    {
        $row = $this->row($ulid, $user);

        return $row->agreement_file !== null ? (string) $row->agreement_file : null;
    }

    /**
     * @param  array<string, mixed>  $a
     */
    public static function compensation(array $a): string
    {
        return ($a['comp_kind'] ?? 'percent') === 'flat' ? '$'.number_format((float) ($a['comp_value'] ?? 0)).' flat' : rtrim(rtrim(number_format((float) ($a['comp_value'] ?? 0), 3), '0'), '.').'% of the price';
    }

    /**
     * The settlement's rules for the agreement: a specific, objectively ascertainable amount or rate, never open-ended.
     *
     * @param  array<string, mixed>  $a
     */
    private static function checkAgreement(array $a): void
    {
        $value = $a['comp_value'] ?? null;
        $kind = $a['comp_kind'] ?? null;
        if (! in_array($kind, ['percent', 'flat'], true) || ! is_numeric($value) || (float) $value <= 0 || ($kind === 'percent' && (float) $value > 10)) {
            throw ValidationException::withMessages(['comp_value' => 'Write the compensation as a specific percent of the price or a dollar amount. It can\'t be open-ended ("whatever the seller offers").']);
        }
        if (! is_numeric($a['months'] ?? null) || (int) $a['months'] < 1 || (int) $a['months'] > 24) {
            throw ValidationException::withMessages(['months' => 'How long the agreement lasts: 1 to 24 months.']);
        }
    }

    /** The appointment, when this user may open it (their own, or a manager who can see the contact); 404 otherwise. */
    public function open(string $ulid, User $user): \stdClass
    {
        return $this->row($ulid, $user);
    }

    private function row(string $ulid, User $user): \stdClass
    {
        $row = DB::connection(Connections::cell())->table('appt_consultations')->where('tenant_id', $this->tenants->id())->where('ulid', $ulid)->first();
        if (! $row instanceof \stdClass) {
            abort(404);
        }
        $contact = Contact::query()->whereKey((int) $row->contact_id)->first();
        abort_unless($row->user_id === $user->id || ($contact !== null && $this->visibility->canSee($user, $contact) && CrmAccess::manager($user)), 404);

        return $row;
    }
}
