<?php

declare(strict_types=1);

namespace Modules\Network;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Core\Audit\AuditLogger;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\Attributes\CrossTenantAccess;
use Modules\Core\Tenancy\Host;
use Modules\Core\Tenancy\Models\Tenant;
use Modules\Core\Tenancy\TenantContext;
use Modules\Crm\Actions\ManageContacts;
use Modules\Network\Mail\NetworkMail;
use Modules\Transactions\DealAccess;
use Modules\Transactions\Models\Deal;
use Modules\Transactions\Referrals;
use stdClass;
use Throwable;

/**
 * Agent-to-agent referrals between RightAlly brokerages (AR1, specs/network/01).
 *
 * - The sending agent confirms the client agreed, and sends the client's needs plus their contact details. Until the
 *   receiving agent accepts, they see only the needs (side, area, price, timing, language), never who the client is.
 * - Accepting records the referral agreement (the fee, who agreed, when) and puts the client in the receiving agent's
 *   contacts. Declining or not answering in 72 hours removes the client's details.
 * - The receiving agent keeps the sender up to date (under contract, closed, fell through); fees are paid brokerage
 *   to brokerage outside RightAlly; the receiving side says when it sent the fee and the sending side confirms.
 * - RightAlly takes no share of referral fees and moves no money.
 */
#[CrossTenantAccess('Referrals go between brokerages; each side only sees its own referrals.')]
final class NetworkReferrals
{
    public const ANSWER_HOURS = 72;

    public const STATUSES = ['sent' => 'Waiting for an answer', 'accepted' => 'Accepted, working with the client', 'declined' => 'Declined', 'expired' => 'Not answered in time',
        'under_contract' => 'Under contract', 'closed' => 'Closed', 'fell_through' => 'Didn\'t work out'];

    public function __construct(private readonly TenantContext $tenants, private readonly Profiles $profiles) {}

    /**
     * Sends a referral to another network agent.
     *
     * @param  array{to: string, side: string, area: string, price_band?: string|null, timing?: string|null, language?: string|null, needs?: string|null, fee_percent?: string|null, client_agreed: bool, first_name: string, last_name?: string|null, email?: string|null, phone?: string|null}  $in
     */
    public function send(User $by, array $in): string
    {
        $from = $this->profiles->mine($by) ?? throw ValidationException::withMessages(['network' => 'Set up your network profile first.']);
        $to = $this->profiles->byUlid($in['to']);
        if ($to === null || $to->status !== 'active' || ! $this->profiles->brokerage((int) $to->tenant_id)['enabled']) {
            throw ValidationException::withMessages(['to' => 'That agent isn\'t taking referrals right now.']);
        }
        if ((int) $to->id === (int) $from->id) {
            throw ValidationException::withMessages(['to' => 'Choose another agent.']);
        }
        if (! $in['client_agreed']) {
            throw ValidationException::withMessages(['client_agreed' => 'Tick that your client agreed to you passing on their details.']);
        }
        if (! in_array($in['side'], ['buyer', 'seller'], true) || trim($in['area']) === '' || trim($in['first_name']) === '') {
            throw ValidationException::withMessages(['side' => 'Say whether the client is buying or selling, where, and their first name.']);
        }
        $email = isset($in['email']) && trim((string) $in['email']) !== '' ? mb_strtolower(trim((string) $in['email'])) : null;
        $phone = isset($in['phone']) && trim((string) $in['phone']) !== '' ? trim((string) $in['phone']) : null;
        if ($email === null && $phone === null) {
            throw ValidationException::withMessages(['email' => 'Give the client\'s email or phone so the agent can reach them.']);
        }
        $fee = isset($in['fee_percent']) && $in['fee_percent'] !== '' ? (string) $in['fee_percent'] : $this->profiles->brokerage()['default_fee_percent'];
        if (! is_numeric($fee) || (float) $fee <= 0 || (float) $fee > 50) {
            throw ValidationException::withMessages(['fee_percent' => 'Enter a referral fee between 1% and 50%.']);
        }
        $ulid = (string) Str::ulid();
        DB::connection(Connections::control())->table('network_referrals')->insert(['ulid' => $ulid, 'from_profile_id' => $from->id, 'to_profile_id' => $to->id,
            'side' => $in['side'], 'area' => mb_substr(trim($in['area']), 0, 120), 'price_band' => self::short($in['price_band'] ?? null, 40), 'timing' => self::short($in['timing'] ?? null, 60),
            'language' => self::short($in['language'] ?? null, 30), 'needs' => self::short($in['needs'] ?? null, 600),
            'client' => Crypt::encryptString((string) json_encode(['first_name' => mb_substr(trim($in['first_name']), 0, 60), 'last_name' => self::short($in['last_name'] ?? null, 60),
                'email' => $email, 'phone' => $phone])),
            'consent_at' => now(), 'fee_percent' => round((float) $fee, 2), 'status' => 'sent', 'created_at' => now(), 'updated_at' => now()]);
        app(AuditLogger::class)->record('network.referral.sent', metadata: ['referral' => $ulid, 'to' => $to->ulid, 'fee' => $fee], severity: 'notice');
        $this->tell($to, new NetworkMail('received', "New referral from {$from->name}: a ".($in['side'] === 'buyer' ? 'buyer' : 'seller')." in {$in['area']}", [
            "{$from->name} of {$from->brokerage} has a client ".($in['side'] === 'buyer' ? 'buying' : 'selling')." in {$in['area']} and would like you to help.",
            "Referral fee: {$fee}% of your side's commission, paid brokerage to brokerage at closing.",
            'Please answer within '.self::ANSWER_HOURS.' hours. You\'ll see the client\'s details once you accept.',
        ], $this->url($to, '/network?tab=received')));

        return $ulid;
    }

    /** The receiving agent accepts and agrees to the fee. The client goes into their contacts. */
    public function accept(User $by, string $ulid): stdClass
    {
        [$r, $mine] = $this->forReceiver($by, $ulid);
        if ($r->status !== 'sent') {
            throw ValidationException::withMessages(['referral' => 'This referral has already been answered.']);
        }
        $from = $this->profiles->find((int) $r->from_profile_id);
        $client = self::client($r);
        $agreement = "{$mine->name} ({$mine->brokerage}) agrees to pay ".self::pct($r)."% of the commission they earn on this client's {$r->side} side to {$from?->brokerage}, "
            .'brokerage to brokerage, at closing. Agreed in RightAlly on '.now()->toDateString().'.';
        DB::connection(Connections::control())->table('network_referrals')->where('id', $r->id)->update(['status' => 'accepted', 'responded_at' => now(), 'agreed_by' => $by->name,
            'agreement' => $agreement, 'last_update_at' => now(), 'updated_at' => now()]);
        try {
            app(ManageContacts::class)->create(['first_name' => (string) $client['first_name'], 'last_name' => (string) ($client['last_name'] ?? ''), 'email' => $client['email'] ?? null,
                'phone' => $client['phone'] ?? null, 'source' => 'referral', 'origin' => 'personal', 'owner_user_id' => $by->id], $by);
        } catch (Throwable) {
            // already a contact, or the details need a fix: the agent still sees them on the referral
        }
        app(AuditLogger::class)->record('network.referral.accepted', metadata: ['referral' => $ulid, 'fee' => (string) $r->fee_percent], severity: 'notice');
        if ($from !== null) {
            $this->tell($from, new NetworkMail('answered', "{$mine->name} accepted your referral", ["{$mine->name} of {$mine->brokerage} accepted your referral for {$client['first_name']} in {$r->area}.",
                'Their agreement: '.$agreement], $this->url($from, '/network?tab=sent')));
        }

        return DB::connection(Connections::control())->table('network_referrals')->where('id', $r->id)->first() ?? $r;
    }

    public function decline(User $by, string $ulid, ?string $reason): void
    {
        [$r, $mine] = $this->forReceiver($by, $ulid);
        if ($r->status !== 'sent') {
            throw ValidationException::withMessages(['referral' => 'This referral has already been answered.']);
        }
        DB::connection(Connections::control())->table('network_referrals')->where('id', $r->id)->update(['status' => 'declined', 'responded_at' => now(), 'client' => null,
            'reason' => self::short($reason, 200), 'updated_at' => now()]);
        app(AuditLogger::class)->record('network.referral.declined', metadata: ['referral' => $ulid]);
        if (($from = $this->profiles->find((int) $r->from_profile_id)) !== null) {
            $this->tell($from, new NetworkMail('answered', "{$mine->name} can't take your referral", ["{$mine->name} declined your referral in {$r->area}."
                .($reason !== null && trim($reason) !== '' ? ' Their reason: '.trim($reason) : ''), 'Find another agent in the referral network.'], $this->url($from, '/network?tab=find')));
        }
    }

    /**
     * The receiving agent keeps the sender up to date.
     *
     * @param  array{status: string, deal?: string|null, fee_due?: string|null, closed_on?: string|null}  $in
     */
    public function update(User $by, string $ulid, array $in): void
    {
        [$r, $mine] = $this->forReceiver($by, $ulid);
        $allowed = ['accepted' => ['under_contract', 'fell_through', 'accepted'], 'under_contract' => ['closed', 'fell_through', 'under_contract'], 'closed' => ['closed']];
        if (! in_array($in['status'], $allowed[$r->status] ?? [], true)) {
            throw ValidationException::withMessages(['status' => 'That update doesn\'t fit where this referral is.']);
        }
        $row = ['status' => $in['status'], 'last_update_at' => now(), 'updated_at' => now()];
        if (($in['deal'] ?? null) !== null && $in['deal'] !== '') {
            $row['deal_ulid'] = $this->linkDeal($by, $r, (string) $in['deal']);
        }
        $due = $in['fee_due'] ?? null;
        if ($in['status'] === 'closed') {
            if (! is_numeric($due) || (float) $due <= 0) {
                throw ValidationException::withMessages(['fee_due' => 'Enter the referral fee owed, in dollars.']);
            }
            $row['fee_due_cents'] = (int) round((float) $due * 100);
            $row['closed_on'] = CarbonImmutable::parse((string) ($in['closed_on'] ?? now()->toDateString()))->toDateString();
        }
        DB::connection(Connections::control())->table('network_referrals')->where('id', $r->id)->update($row);
        app(AuditLogger::class)->record('network.referral.updated', metadata: ['referral' => $ulid, 'status' => $in['status']]);
        if ($in['status'] !== $r->status && ($from = $this->profiles->find((int) $r->from_profile_id)) !== null) {
            $line = match ($in['status']) {
                'under_contract' => 'Your referral is under contract.',
                'closed' => 'Your referral closed. Referral fee owed to your brokerage: $'.number_format((float) $due, 2).'.',
                default => 'Your referral didn\'t work out this time.',
            };
            $this->tell($from, new NetworkMail('update', "Referral update from {$mine->name}", [$line.' ('.$r->area.')'], $this->url($from, '/network?tab=sent')));
        }
    }

    /** The receiving side says the fee was sent; the sending side confirms it arrived. */
    public function fee(User $by, string $ulid, string $step): void
    {
        $r = DB::connection(Connections::control())->table('network_referrals')->where('ulid', $ulid)->first();
        $mine = $this->profiles->mine($by);
        abort_if($r === null || $mine === null || $r->status !== 'closed', 404);
        if ($step === 'sent' && (int) $r->to_profile_id === (int) $mine->id && $r->fee_sent_at === null) {
            DB::connection(Connections::control())->table('network_referrals')->where('id', $r->id)->update(['fee_sent_at' => now(), 'updated_at' => now()]);
            if (($from = $this->profiles->find((int) $r->from_profile_id)) !== null) {
                $this->tell($from, new NetworkMail('update', "{$mine->name} says the referral fee was sent", ['Please confirm in RightAlly once it reaches your brokerage.'], $this->url($from, '/network?tab=sent')));
            }
        } elseif ($step === 'paid' && (int) $r->from_profile_id === (int) $mine->id && $r->fee_paid_at === null) {
            DB::connection(Connections::control())->table('network_referrals')->where('id', $r->id)->update(['fee_paid_at' => now(), 'fee_sent_at' => $r->fee_sent_at ?? now(), 'updated_at' => now()]);
        } else {
            throw ValidationException::withMessages(['fee' => 'That step isn\'t yours to confirm, or it\'s already done.']);
        }
        app(AuditLogger::class)->record('network.referral.fee', metadata: ['referral' => $ulid, 'step' => $step], severity: 'notice');
    }

    /**
     * Hourly: referrals not answered within 72 hours expire and the client's details are removed.
     */
    public function expire(): int
    {
        $db = DB::connection(Connections::control())->table('network_referrals');
        $late = $db->clone()->where('status', 'sent')->where('created_at', '<=', now()->subHours(self::ANSWER_HOURS))->get();
        foreach ($late as $r) {
            $db->clone()->where('id', $r->id)->update(['status' => 'expired', 'client' => null, 'updated_at' => now()]);
            if (($from = $this->profiles->find((int) $r->from_profile_id)) !== null) {
                $this->tell($from, new NetworkMail('answered', 'Your referral wasn\'t answered in time', ["Your referral in {$r->area} wasn't answered within ".self::ANSWER_HOURS.' hours, so it was withdrawn and the client\'s details removed.',
                    'Send it to another agent in the referral network.'], $this->url($from, '/network?tab=find')));
            }
        }

        return $late->count();
    }

    /**
     * The user's referrals, received and sent, in plain words.
     *
     * @return array{received: list<array<string, mixed>>, sent: list<array<string, mixed>>}
     */
    public function forUser(User $user): array
    {
        $mine = $this->profiles->mine($user);
        if ($mine === null) {
            return ['received' => [], 'sent' => []];
        }
        $db = DB::connection(Connections::control());
        $rows = $db->table('network_referrals')->where(fn ($w) => $w->where('to_profile_id', $mine->id)->orWhere('from_profile_id', $mine->id))->orderByDesc('id')->limit(200)->get();
        $people = $db->table('network_profiles')->whereIn('id', $rows->pluck('from_profile_id')->merge($rows->pluck('to_profile_id'))->unique()->all())->get()->keyBy('id');
        $out = ['received' => [], 'sent' => []];
        foreach ($rows as $r) {
            $received = (int) $r->to_profile_id === (int) $mine->id;
            $other = $people[$received ? $r->from_profile_id : $r->to_profile_id] ?? null;
            $showClient = ! $received || in_array($r->status, ['accepted', 'under_contract', 'closed', 'fell_through'], true);
            $out[$received ? 'received' : 'sent'][] = ['ulid' => (string) $r->ulid, 'status' => (string) $r->status, 'status_label' => self::STATUSES[(string) $r->status] ?? (string) $r->status,
                'side' => (string) $r->side, 'area' => (string) $r->area, 'price_band' => $r->price_band, 'timing' => $r->timing, 'language' => $r->language, 'needs' => $r->needs,
                'fee_percent' => self::pct($r), 'other' => $other !== null ? ['name' => (string) $other->name, 'brokerage' => (string) $other->brokerage, 'ulid' => (string) $other->ulid] : null,
                'client' => $showClient && $r->client !== null ? self::client($r) : null, 'agreement' => $r->agreement, 'reason' => $r->reason, 'deal' => $r->deal_ulid,
                'fee_due_cents' => $r->fee_due_cents !== null ? (int) $r->fee_due_cents : null, 'closed_on' => $r->closed_on, 'fee_sent' => $r->fee_sent_at !== null, 'fee_paid' => $r->fee_paid_at !== null,
                'answer_by' => $r->status === 'sent' ? CarbonImmutable::parse((string) $r->created_at)->addHours(self::ANSWER_HOURS)->toIso8601String() : null,
                'sent_at' => substr((string) $r->created_at, 0, 10)];
        }

        return $out;
    }

    /** @return array{0: stdClass, 1: stdClass} */
    private function forReceiver(User $by, string $ulid): array
    {
        $mine = $this->profiles->mine($by);
        $r = DB::connection(Connections::control())->table('network_referrals')->where('ulid', $ulid)->first();
        abort_if($mine === null || $r === null || (int) $r->to_profile_id !== (int) $mine->id, 404);

        return [$r, $mine];
    }

    /** Puts the referral on the receiving agent's deal, so the commission includes the outgoing referral fee. */
    private function linkDeal(User $by, stdClass $r, string $dealUlid): string
    {
        $deal = Deal::query()->where('ulid', $dealUlid)->first();
        if ($deal === null || ! DealAccess::canSee($by, $deal)) {
            throw ValidationException::withMessages(['deal' => 'Choose one of your deals.']);
        }
        if ($r->deal_ulid === null) {
            $from = $this->profiles->find((int) $r->from_profile_id);
            app(Referrals::class)->add($deal, 'out', (string) ($from->brokerage ?? 'Referring brokerage'), (string) ($from->name ?? 'Referring agent'), null, self::pct($r), null);
        }

        return $deal->ulid;
    }

    private function tell(stdClass $profile, NetworkMail $mail): void
    {
        $tenant = Tenant::query()->find((int) $profile->tenant_id);
        if ($tenant === null) {
            return;
        }
        $this->tenants->runAs($tenant, function () use ($profile, $mail): void {
            User::query()->find((int) $profile->user_id)?->notify($mail);
        });
    }

    private function url(stdClass $profile, string $path): string
    {
        $slug = Tenant::query()->whereKey((int) $profile->tenant_id)->value('slug');

        return 'https://'.Host::platformHostFor((string) $slug).$path;
    }

    /** @return array{first_name: string, last_name?: string|null, email?: string|null, phone?: string|null} */
    private static function client(stdClass $r): array
    {
        try {
            $c = (array) json_decode(Crypt::decryptString((string) $r->client), true);
        } catch (Throwable) {
            $c = [];
        }

        return ['first_name' => (string) ($c['first_name'] ?? ''), 'last_name' => $c['last_name'] ?? null, 'email' => $c['email'] ?? null, 'phone' => $c['phone'] ?? null];
    }

    private static function pct(stdClass $r): string
    {
        return rtrim(rtrim((string) $r->fee_percent, '0'), '.');
    }

    private static function short(mixed $v, int $max): ?string
    {
        return is_string($v) && trim($v) !== '' ? mb_substr(trim($v), 0, $max) : null;
    }
}
