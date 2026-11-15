<?php

declare(strict_types=1);

namespace Modules\Crm\Postcards;

use App\Models\User;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Modules\AI\Exceptions\InsufficientCredits;
use Modules\Billing\Credits\CreditLedger;
use Modules\Billing\Credits\RateCard;
use Modules\Connect\AppRegistry;
use Modules\Core\Audit\AuditLogger;
use Modules\Core\Connect\AppCatalog;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\TenantContext;
use Modules\Crm\ContactVisibility;
use Modules\Crm\Enums\Lifecycle;
use Modules\Crm\FairHousing;
use Modules\Crm\Models\Contact;
use Modules\Crm\SmartLists;
use Modules\Listings\Models\Listing;

/**
 * Direct mail (specs/connect/18, C7c): just listed, just sold, open house and farming postcards to a smart list,
 * printed and mailed by Lob on RightAlly's account and paid with RightAlly Credits at HQ's price per card.
 *
 * - Price first: cards and credits are shown before anything is bought. On approval every address is checked
 *   with Lob's US verification; only deliverable cards are paid for; cards Lob refuses or the person cancels are
 *   given back.
 * - People tagged "Do not mail" or archived never get mail. The message passes the Fair Housing check.
 * - Each card carries a QR code to the listing or funnel (tagged so visits count) and the brokerage's name with
 *   Equal Housing Opportunity.
 * - Cards go out the next business day, so a run can be cancelled until then.
 */
final class Postcards
{
    public const ACTION = 'mail.postcards';

    public const API = 'https://api.lob.com/v1/';

    public const KINDS = ['just_listed' => 'Just listed', 'just_sold' => 'Just sold', 'open_house' => 'Open house', 'farming' => 'Neighbourhood update'];

    public function __construct(private readonly TenantContext $tenants, private readonly CreditLedger $credits) {}

    public function ready(): bool
    {
        $app = app(AppCatalog::class)->find('direct_mail');

        return $app !== null && app(AppRegistry::class)->ready($app) && $this->keys()['api_key'] !== '';
    }

    /** HQ's price per card in cents (printing, postage and margin), by size. */
    public function centsPerCard(string $size): int
    {
        $k = $this->keys();

        return max(1, (int) ($size === '6x9' ? $k['price_6x9_cents'] : $k['price_4x6_cents']));
    }

    public function creditsFor(int $cents): int
    {
        return (int) ceil($cents * 1_000_000 / max(1, app(RateCard::class)->creditValueMicrocents()));
    }

    /**
     * @param  array<string, mixed>  $in  kind, size, smart_list, listing, headline, message, link, from_name, from_line1, from_city, from_state, from_zip
     */
    public function draft(User $by, array $in): PostcardRun
    {
        if (! $this->ready()) {
            throw ValidationException::withMessages(['kind' => 'Direct mail isn\'t switched on yet. Ask RightAlly support.']);
        }
        $kind = isset(self::KINDS[$in['kind'] ?? '']) ? (string) $in['kind'] : 'farming';
        $size = ($in['size'] ?? '') === '6x9' ? '6x9' : '4x6';
        $headline = mb_substr(trim(strip_tags((string) ($in['headline'] ?? ''))), 0, 60);
        $message = mb_substr(trim(strip_tags((string) ($in['message'] ?? ''))), 0, 400);
        $link = trim((string) ($in['link'] ?? ''));
        $from = ['name' => mb_substr(trim((string) ($in['from_name'] ?? '')), 0, 40), 'address_line1' => mb_substr(trim((string) ($in['from_line1'] ?? '')), 0, 64),
            'city' => mb_substr(trim((string) ($in['from_city'] ?? '')), 0, 40), 'state' => strtoupper(mb_substr(trim((string) ($in['from_state'] ?? '')), 0, 2)),
            'zip' => mb_substr(trim((string) ($in['from_zip'] ?? '')), 0, 10)];
        $errors = array_filter([
            'smart_list' => app(SmartLists::class)->filtersFor((string) ($in['smart_list'] ?? ''), $by) === null ? 'Choose who gets it: one of your smart lists.' : null,
            'headline' => $headline === '' ? 'Write a short headline.' : null,
            'message' => $message === '' ? 'Write the message on the back.' : null,
            'link' => ! str_starts_with($link, 'https://') ? 'Add the web address the QR code opens (a listing or funnel page).' : null,
            'from_line1' => in_array('', $from, true) || preg_match('/^[A-Z]{2}$/', $from['state']) !== 1 ? 'Add the full return address.' : null,
        ]);
        if (($flags = FairHousing::screen($headline.' '.$message, 'ads', 'blocked', 'Postcard')) !== []) {
            $errors['message'] = 'Fair Housing: please remove "'.implode('", "', $flags).'". Describe the home and the area, not who should live there.';
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
        $run = PostcardRun::query()->create(['user_id' => $by->id, 'kind' => $kind, 'size' => $size, 'smart_list' => (string) $in['smart_list'],
            'listing_ulid' => is_string($in['listing'] ?? null) && $in['listing'] !== '' ? $in['listing'] : null, 'headline' => $headline, 'message' => $message,
            'link' => mb_substr($link, 0, 400), 'from' => $from, 'status' => 'draft']);
        $run->forceFill(['link' => $link.(str_contains($link, '?') ? '&' : '?').http_build_query(['utm_source' => 'postcard', 'utm_medium' => 'direct_mail',
            'utm_campaign' => 'rightally-'.strtolower($run->ulid)])])->save();
        $count = $this->audience($run, $by)->count();
        $cents = $this->centsPerCard($size);
        $run->forceFill(['cards' => $count, 'cents_per_card' => $cents, 'credits' => $this->creditsFor($cents) * $count])->save();

        return $run;
    }

    /**
     * Approve: take the credits, check every address, mail the deliverable ones (next business day), give back the
     * credits for the rest.
     */
    public function approve(PostcardRun $run, User $by): PostcardRun
    {
        abort_unless($run->user_id === $by->id, 403);
        if ($run->status !== 'draft') {
            throw ValidationException::withMessages(['run' => 'This mailing has already been sent or cancelled.']);
        }
        $people = $this->audience($run, $by)->limit(5000)->get();
        if ($people->isEmpty()) {
            throw ValidationException::withMessages(['run' => 'Nobody on this list has a full mailing address.']);
        }
        // Check every address first, so only deliverable cards are paid for.
        $ok = $this->verify($run, $people);
        $per = $this->creditsFor($run->cents_per_card);
        $total = $per * count($ok);
        if ($ok === []) {
            $run->forceFill(['status' => 'failed', 'cards' => 0, 'undeliverable' => $people->count(), 'credits' => 0])->save();

            throw ValidationException::withMessages(['run' => 'None of these addresses can be mailed.']);
        }
        try {
            $this->credits->charge(self::ACTION, $by->id, 'postcards:'.$run->ulid, $total);
        } catch (InsufficientCredits $e) {
            throw ValidationException::withMessages(['run' => $e->getMessage().' This mailing needs '.number_format($total).' credits.']);
        }
        $sendDate = self::nextBusinessDay();
        $run->forceFill(['status' => 'scheduled', 'credits' => $total, 'send_date' => $sendDate->toDateString()])->save();
        $sent = $this->mail($run, $ok, $sendDate);
        $back = (count($ok) - $sent) * $per;
        if ($back > 0) {
            $back = $this->refund($run, $back, 'Postcards Lob refused');
        }
        $run->forceFill(['cards' => $sent, 'undeliverable' => $people->count() - count($ok), 'credits_refunded' => $back, 'status' => $sent > 0 ? 'scheduled' : 'failed'])->save();
        app(AuditLogger::class)->record('crm.postcards.approved', $run, metadata: ['cards' => $sent, 'credits' => $total - $back], label: $run->headline, severity: 'notice');

        return $run;
    }

    /** Cancel before the send date: every card cancelled with Lob, its credits given back. */
    public function cancel(PostcardRun $run, User $by): int
    {
        abort_unless($run->user_id === $by->id, 403);
        if ($run->status !== 'scheduled' || $run->send_date === null || ! $run->send_date->isFuture()) {
            throw ValidationException::withMessages(['run' => 'It\'s too late to cancel: the cards are being printed.']);
        }
        $db = DB::connection(Connections::cell());
        $cancelled = 0;
        foreach ($db->table('crm_postcard_items')->where('run_id', $run->id)->where('status', 'sent')->get() as $item) {
            $r = $this->http()->delete('postcards/'.$item->lob_id);
            if ($r->successful()) {
                $db->table('crm_postcard_items')->where('id', $item->id)->update(['status' => 'cancelled']);
                $cancelled++;
            }
        }
        $back = $cancelled > 0 ? $this->refund($run, $cancelled * $this->creditsFor($run->cents_per_card), 'Postcards cancelled') : 0;
        $run->forceFill(['status' => 'cancelled', 'credits_refunded' => $run->credits_refunded + $back])->save();
        app(AuditLogger::class)->record('crm.postcards.cancelled', $run, metadata: ['cards' => $cancelled], label: $run->headline, severity: 'notice');

        return $cancelled;
    }

    /** The front or back of the card, as HTML (also what the preview shows). */
    public function html(PostcardRun $run, string $side, string $toName = ''): string
    {
        $e = fn (string $s): string => htmlspecialchars($s, ENT_QUOTES);
        $brokerage = (string) $this->tenants->current()->name;
        [$w, $h] = $run->size === '6x9' ? ['9.25in', '6.25in'] : ['6.25in', '4.25in'];
        $base = "<html><head><meta charset=\"utf-8\"><style>body{margin:0;width:{$w};height:{$h};font-family:Helvetica,Arial,sans-serif;color:#0f172a}"
            .'.safe{position:absolute;inset:0.2in;}</style></head><body>';
        if ($side === 'front') {
            $photo = $run->listing_ulid !== null ? (string) (Listing::query()->where('ulid', $run->listing_ulid)->value('cover_path') ?? '') : '';
            $img = $photo !== '' ? '<img src="'.$e(str_starts_with($photo, 'http') ? $photo : url('/storage/'.$photo)).'" style="position:absolute;inset:0;width:100%;height:100%;object-fit:cover">' : '';

            return $base.$img.'<div class="safe"><div style="position:absolute;bottom:0;left:0;right:0;background:rgba(15,23,42,.85);color:#fff;padding:14px">'
                .'<div style="font-size:13px;letter-spacing:.08em;text-transform:uppercase">'.$e(self::KINDS[$run->kind] ?? '').'</div>'
                .'<div style="font-size:26px;font-weight:700">'.$e($run->headline).'</div><div style="font-size:12px">'.$e($brokerage).'</div></div></div></body></html>';
        }
        $qr = (new Writer(new ImageRenderer(new RendererStyle(140, 1), new SvgImageBackEnd)))->writeString($run->link);
        $qr = trim(substr($qr, (int) strpos($qr, "\n") + 1));

        return $base.'<div class="safe" style="right:3.4in"><div style="font-size:15px;line-height:1.35;white-space:pre-wrap">'.$e(($toName !== '' ? 'Hi '.$toName.",\n\n" : '').$run->message).'</div>'
            .'<div style="margin-top:10px;display:flex;gap:10px;align-items:center">'.$qr.'<div style="font-size:11px">Scan for photos, price and details</div></div>'
            .'<div style="position:absolute;bottom:0;font-size:9px;color:#475569">'.$e($brokerage).' · '.$e($run->from['address_line1'].', '.$run->from['city'].' '.$run->from['state'].' '.$run->from['zip'])
            .' · Equal Housing Opportunity. If your home is listed with another broker, please disregard.</div></div></body></html>';
    }

    /** @return Builder<Contact> people on the list with a full mailing address who may get mail */
    public function audience(PostcardRun $run, User $by): Builder
    {
        $q = app(ContactVisibility::class)->query($by)->whereNotNull('address_line1')->whereNotNull('city')->whereNotNull('state')->whereNotNull('zip')
            ->where('lifecycle', '!=', Lifecycle::Archived->value)->whereDoesntHave('tags', fn ($t) => $t->where('name', 'Do not mail'));
        $filters = app(SmartLists::class)->filtersFor($run->smart_list, $by);

        return $filters === null ? $q->whereRaw('1 = 0') : app(SmartLists::class)->apply($q, $filters);
    }

    /**
     * Lob's US verification for each person; undeliverable ones are recorded and left out.
     *
     * @param  Collection<int, Contact>  $people
     * @return list<array{contact: Contact, to: array<string, string>}>
     */
    private function verify(PostcardRun $run, Collection $people): array
    {
        $db = DB::connection(Connections::cell());
        $ok = [];
        foreach ($people as $c) {
            $v = $this->http()->post('us_verifications', array_filter(['primary_line' => $c->address_line1, 'secondary_line' => $c->address_line2, 'city' => $c->city,
                'state' => $c->state, 'zip_code' => $c->zip]));
            if ($v->successful() && str_starts_with((string) $v->json('deliverability'), 'deliverable')) {
                $ok[] = ['contact' => $c, 'to' => array_filter(['name' => mb_substr(trim($c->first_name.' '.$c->last_name), 0, 40), 'address_line1' => (string) $v->json('primary_line'),
                    'address_line2' => (string) $v->json('secondary_line'), 'address_city' => (string) $v->json('components.city'), 'address_state' => (string) $v->json('components.state'),
                    'address_zip' => (string) $v->json('components.zip_code')])];
            } else {
                $db->table('crm_postcard_items')->insertOrIgnore(['tenant_id' => $run->tenant_id, 'run_id' => $run->id, 'contact_id' => $c->id, 'lob_id' => null, 'status' => 'undeliverable',
                    'created_at' => CarbonImmutable::now()]);
            }
        }

        return $ok;
    }

    /** @param  list<array{contact: Contact, to: array<string, string>}>  $ok */
    private function mail(PostcardRun $run, array $ok, CarbonImmutable $sendDate): int
    {
        $db = DB::connection(Connections::cell());
        $sent = 0;
        $from = ['name' => $run->from['name'], 'address_line1' => $run->from['address_line1'], 'address_city' => $run->from['city'], 'address_state' => $run->from['state'],
            'address_zip' => $run->from['zip']];
        foreach ($ok as ['contact' => $c, 'to' => $to]) {
            $r = $this->http()->withHeaders(['Idempotency-Key' => 'ra-'.$run->ulid.'-'.$c->id])->post('postcards', [
                'description' => 'RightAlly '.$run->ulid, 'size' => $run->size, 'use_type' => 'marketing', 'send_date' => $sendDate->toDateString(), 'to' => $to, 'from' => $from,
                'front' => $this->html($run, 'front'), 'back' => $this->html($run, 'back', $c->first_name), 'metadata' => ['run' => $run->ulid]]);
            [$status, $lobId] = $r->successful() ? ['sent', (string) $r->json('id')] : ['failed', null];
            $db->table('crm_postcard_items')->insertOrIgnore(['tenant_id' => $run->tenant_id, 'run_id' => $run->id, 'contact_id' => $c->id, 'lob_id' => $lobId, 'status' => $status,
                'created_at' => CarbonImmutable::now()]);
            $sent += $status === 'sent' ? 1 : 0;
        }

        return $sent;
    }

    /** Credits back to the person who paid: the charge's own refund the first time, a correction after that. */
    private function refund(PostcardRun $run, int $credits, string $why): int
    {
        $back = $this->credits->refundPart('postcards:'.$run->ulid, $credits, $why);
        if ($back === 0 && $credits > 0) {
            $this->credits->add($run->user_id, $credits, 'adjust', $why.' ('.$run->headline.')', null, 'postcards-back:'.$run->ulid.':'.$run->credits_refunded);
            $back = $credits;
        }

        return $back;
    }

    private static function nextBusinessDay(): CarbonImmutable
    {
        $d = CarbonImmutable::now('America/New_York')->addDay()->startOfDay();
        while ($d->isWeekend()) {
            $d = $d->addDay();
        }

        return $d;
    }

    private function http(): PendingRequest
    {
        return Http::withBasicAuth($this->keys()['api_key'], '')->acceptJson()->asJson()->timeout(20)->baseUrl(self::API);
    }

    /** @return array{api_key: string, price_4x6_cents: string, price_6x9_cents: string} */
    private function keys(): array
    {
        $app = app(AppCatalog::class)->find('direct_mail');
        $k = $app !== null ? app(AppRegistry::class)->secrets($app) : [];

        return ['api_key' => (string) ($k['api_key'] ?? ''), 'price_4x6_cents' => (string) ($k['price_4x6_cents'] ?? '99'), 'price_6x9_cents' => (string) ($k['price_6x9_cents'] ?? '149')];
    }
}
