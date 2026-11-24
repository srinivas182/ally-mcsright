<?php

declare(strict_types=1);

namespace Modules\Listings\Idx;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Modules\Core\Audit\AuditLogger;
use Modules\Core\Identity\Enums\UserKind;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\Host;
use Modules\Core\Tenancy\Models\Tenant;
use Modules\Core\Tenancy\TenantContext;
use Modules\Listings\Access\MlsAccess;
use Modules\Listings\Idx\Mail\IdxUpdateMail;
use Modules\Listings\Models\MlsAuthorization;
use Modules\Listings\Models\MlsSource;
use Modules\Roster\Brokerage\BrokerageLicences;
use stdClass;
use Throwable;

/**
 * IDX4: each brokerage's MLS boards, one at a time:
 *
 *   demo → awaiting signature → sent → waiting on the MLS → live
 *                     ↘ needs fixing (with a note) ↗              ↘ cancelled (listings hidden at once)
 *
 * Going live turns on the brokerage's MLS licence for that board's feed (the licence every search checks); cancelling
 * revokes it. A brokerage that already has its own key for a board (for example from MLS United) can give it: going
 * live then uses a feed only that brokerage can use. Brokerages never see connector names.
 */
final class Subscriptions
{
    /** Plain words for each status, as the brokerage sees them. */
    public const STATUSES = [
        'demo' => 'Demo listings: enter your MLS details',
        'awaiting_signature' => 'Waiting for signatures',
        'review' => 'Review and complete your agreement', // IDX12
        'hq_check' => 'Signed: RightAlly is checking it', // IDX11
        'sent' => 'Sent to the MLS',
        'mls_review' => 'Waiting on the MLS',
        'live' => 'Live',
        'needs_fixing' => 'Needs fixing',
        'cancelled' => 'Cancelled',
    ];

    /** What HQ may move a board to from each status (going live and cancelling have their own steps). */
    private const MOVES = [
        'demo' => ['needs_fixing'],
        'awaiting_signature' => ['sent', 'needs_fixing'],
        'review' => ['needs_fixing'], // IDX12: the brokerage completes the board's form
        'hq_check' => ['needs_fixing'], // IDX11: sending to the MLS is its own step
        'sent' => ['mls_review', 'needs_fixing'],
        'mls_review' => ['needs_fixing', 'sent'],
        'needs_fixing' => ['review', 'awaiting_signature', 'hq_check', 'sent', 'mls_review'],
        'live' => [],
        'cancelled' => [],
    ];

    /** Days after which an unsigned agreement gets a reminder (then stops). */
    public const REMIND_AFTER_DAYS = [1, 3];

    public function __construct(private readonly Coverage $coverage, private readonly MlsAccess $access, private readonly AuditLogger $audit, private readonly TenantContext $tenants) {}

    private function db(): ConnectionInterface
    {
        return DB::connection(Connections::control());
    }

    /**
     * A brokerage's boards with their status, in plain words (never connector names).
     *
     * @return list<array<string, mixed>>
     */
    public function forTenant(int $tenantId): array
    {
        $boards = $this->db()->table('idx_boards')->get()->keyBy('id');

        return array_values($this->db()->table('idx_subscriptions')->where('tenant_id', $tenantId)->orderBy('id')->get()
            ->map(fn (object $s): array => $this->describe($s, $boards[$s->board_id] ?? null, false))->all());
    }

    /**
     * HQ's queue: every brokerage's boards that are not yet live or cancelled first.
     *
     * @return list<array<string, mixed>>
     */
    public function queue(): array
    {
        $boards = $this->db()->table('idx_boards')->get()->keyBy('id');
        $tenants = Tenant::query()->pluck('name', 'id');

        return array_values($this->db()->table('idx_subscriptions')
            ->orderByRaw("case status when 'live' then 2 when 'cancelled' then 3 else 1 end")->orderBy('status_at')->limit(500)->get()
            ->map(fn (object $s): array => $this->describe($s, $boards[$s->board_id] ?? null, true) + ['brokerage' => (string) ($tenants[$s->tenant_id] ?? 'Unknown')])->all());
    }

    /** The brokerage adds a board it belongs to: it starts on demo listings. */
    public function add(int $tenantId, string $boardUlid, ?User $by): string
    {
        $board = $this->db()->table('idx_boards')->where('ulid', $boardUlid)->first();
        $offered = collect($this->coverage->offered($this->coverage->enabledStates()))->firstWhere('ulid', $boardUlid);
        if ($board === null || $offered === null) {
            throw ValidationException::withMessages(['board' => 'That board is not available yet. Ask RightAlly to add it.']);
        }
        // LV2: a board a brokerage adds itself needs an active licence in the board's state (as at sign-up).
        // (HQ adding a board for a brokerage, and the board chosen at sign-up, were checked already.)
        $tenant = Tenant::query()->find($tenantId);
        if ($by !== null && ! $by->isKind(UserKind::HqStaff) && $tenant !== null && class_exists(BrokerageLicences::class)) {
            $states = (array) ($offered['states'] ?? [$offered['state'] ?? '']);
            $good = app(TenantContext::class)->runAs($tenant, fn (): array => app(BrokerageLicences::class)->goodStates());
            if (array_intersect($states, $good) === []) {
                throw ValidationException::withMessages(['board' => 'Add your active '.implode(' or ', array_filter($states)).' licence in Settings › Licences first: an MLS board needs an active licence in its state.']);
            }
        }
        $existing = $this->db()->table('idx_subscriptions')->where('tenant_id', $tenantId)->where('board_id', $board->id)->first();
        if ($existing !== null && $existing->status !== 'cancelled') {
            throw ValidationException::withMessages(['board' => 'You already have this board.']);
        }
        $row = ['connector' => $board->main_connector, 'status' => 'demo', 'fee_cents' => $offered['fee_cents'], 'client_note' => null, 'reminders' => 0,
            'status_at' => now(), 'cancelled_at' => null, 'live_at' => null, 'mls_authorization_id' => null, 'created_by' => $by?->id, 'updated_at' => now()];
        if ($existing !== null) {
            $this->db()->table('idx_subscriptions')->where('id', $existing->id)->update($row);
            $ulid = (string) $existing->ulid;
        } else {
            $ulid = (string) Str::ulid();
            $this->db()->table('idx_subscriptions')->insert($row + ['ulid' => $ulid, 'tenant_id' => $tenantId, 'board_id' => $board->id, 'created_at' => now()]);
        }
        DemoListings::forget($tenantId);
        $this->audit->record('idx.board.added_by_brokerage', metadata: ['board' => $board->code], severity: 'notice', tenantId: $tenantId);

        return $ulid;
    }

    /**
     * The brokerage gives its MLS details (and its own key, if it has one): the board moves to "waiting for
     * signatures". Also how a brokerage answers "needs fixing".
     *
     * @param  array<string, mixed>  $input
     */
    public function submit(int $tenantId, string $ulid, array $input, User $by): void
    {
        $sub = $this->own($tenantId, $ulid);
        if (! in_array($sub->status, ['demo', 'needs_fixing', 'awaiting_signature', 'review'], true)) {
            throw ValidationException::withMessages(['status' => 'These details can no longer be changed here. Ask RightAlly support.']);
        }
        $board = $this->db()->table('idx_boards')->where('id', $sub->board_id)->first();
        $ownForm = $board instanceof stdClass && BoardForm::inUse($board);
        $data = Validator::make($input, [
            'member_id' => ['required', 'string', 'max:30'],
            'office_id' => ['nullable', 'string', 'max:30'],
            'license_number' => ['required', 'string', 'max:40'],
            'managing_broker_name' => ['required', 'string', 'max:120'],
            'managing_broker_email' => ['required', 'email:rfc', 'max:190'],
            'own_key' => ['nullable', 'string', 'min:10', 'max:2000'],
        ], [], ['member_id' => 'MLS member ID', 'license_number' => 'licence number', 'managing_broker_email' => 'managing broker email'])->validate();
        $row = ['member_id' => trim($data['member_id']), 'office_id' => trim((string) ($data['office_id'] ?? '')) ?: null, 'license_number' => trim($data['license_number']),
            'managing_broker_name' => trim($data['managing_broker_name']), 'managing_broker_email' => mb_strtolower(trim($data['managing_broker_email'])),
            'status' => $ownForm ? 'review' : 'awaiting_signature', 'client_note' => null, 'reminders' => 0, 'status_at' => now(), 'submitted_at' => now(),
            // New details mean a new signature: an earlier signed copy no longer matches them.
            'signed_path' => null, 'signed_at' => null, 'check_result' => null, 'checked_at' => null, 'updated_at' => now()];
        if (($data['own_key'] ?? '') !== '') {
            $row['own_key'] = Crypt::encryptString(trim((string) $data['own_key']));
        }
        $this->db()->table('idx_subscriptions')->where('id', $sub->id)->update($row);
        $this->audit->record('idx.details_submitted', metadata: ['subscription' => $ulid, 'own_key' => ($data['own_key'] ?? '') !== ''], severity: 'notice', tenantId: $tenantId);

        // IDX12: with the board's own form, the brokerage reviews it filled in first; otherwise it goes for signing now.
        if ($ownForm) {
            return;
        }
        try {
            app(Agreements::class)->prepare($this->find($ulid));
        } catch (Throwable $e) {
            report($e); // HQ can still handle the paperwork by hand; the brokerage's details are saved
        }
    }

    /**
     * IDX12: the brokerage checked the board's form filled in, completed anything missing, and sends it for signature.
     *
     * @param  array<string, mixed>  $values
     */
    public function review(int $tenantId, string $ulid, array $values, User $by, bool $send = true): void
    {
        $sub = $this->own($tenantId, $ulid);
        $board = $this->db()->table('idx_boards')->where('id', $sub->board_id)->first();
        if ($sub->status !== 'review' || ! $board instanceof stdClass || ! BoardForm::inUse($board)) {
            throw ValidationException::withMessages(['status' => 'This agreement is not waiting for your review.']);
        }
        $clean = [];
        foreach (BoardForm::fields($board) as $f) {
            if ($f['source'] !== null && $f['source'] !== 'skip') {
                $clean[$f['key']] = trim(mb_substr(strip_tags((string) ($values[$f['key']] ?? '')), 0, 300));
            }
        }
        if (! $send) { // saved for a preview; nothing is sent yet
            $this->db()->table('idx_subscriptions')->where('id', $sub->id)->update(['form_values' => json_encode($clean), 'updated_at' => now()]);

            return;
        }
        if (($missing = BoardForm::clean($board, $clean)) !== []) {
            throw ValidationException::withMessages(['values' => 'Please fill in: '.implode(', ', $missing).'.']);
        }
        $this->db()->table('idx_subscriptions')->where('id', $sub->id)->update(['form_values' => json_encode($clean), 'status' => 'awaiting_signature', 'status_at' => now(),
            'updated_at' => now()]);
        $this->audit->record('idx.form_reviewed', metadata: ['subscription' => $ulid, 'by' => $by->id], severity: 'notice', tenantId: $tenantId);
        try {
            app(Agreements::class)->prepare($this->find($ulid));
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * HQ moves a board along (signed and sent, waiting on the MLS, or needs fixing with a note for the brokerage).
     */
    public function move(string $ulid, string $to, ?string $note, User $by): void
    {
        $sub = $this->find($ulid);
        if (! in_array($to, self::MOVES[$sub->status] ?? [], true)) {
            throw ValidationException::withMessages(['status' => 'A board that is "'.self::STATUSES[$sub->status].'" cannot move to "'.(self::STATUSES[$to] ?? $to).'".']);
        }
        $note = trim((string) $note);
        if ($to === 'needs_fixing' && $note === '') {
            throw ValidationException::withMessages(['note' => 'Say what the brokerage needs to fix.']);
        }
        $this->db()->table('idx_subscriptions')->where('id', $sub->id)->update(['status' => $to, 'client_note' => $to === 'needs_fixing' ? mb_substr($note, 0, 1500) : null,
            'status_at' => now(), 'updated_at' => now()]);
        $this->audit->record('idx.status.'.$to, metadata: ['subscription' => $ulid, 'by' => $by->id], severity: 'notice', tenantId: (int) $sub->tenant_id);
        if ($to === 'needs_fixing') {
            $this->tell($sub, 'Your MLS board needs a fix', [$note, 'Open your MLS boards, fix this and send it again. Your demo listings keep showing meanwhile.'], 'Fix it now');
        }
    }

    /** HQ moves one brokerage's board to a different connector (the board's default stays as it is). */
    public function override(string $ulid, string $connector, User $by): void
    {
        $sub = $this->find($ulid);
        Validator::make(['connector' => $connector], ['connector' => ['required', Rule::in($this->db()->table('idx_connectors')->pluck('key')->all())]])->validate();
        if ($sub->status === 'live') {
            throw ValidationException::withMessages(['connector' => 'This board is live. Cancel it first, or switch it when the new feed is ready.']);
        }
        $this->db()->table('idx_subscriptions')->where('id', $sub->id)->update(['connector' => $connector, 'updated_at' => now()]);
        $this->audit->record('idx.connector.overridden', metadata: ['subscription' => $ulid, 'from' => $sub->connector, 'to' => $connector, 'by' => $by->id], severity: 'warning', tenantId: (int) $sub->tenant_id);
    }

    /**
     * The MLS said yes: turn on the brokerage's licence for the board's feed. With the brokerage's own key, a feed only
     * it can use is made first. Its real listings then replace the demo listings for this board.
     */
    public function goLive(string $ulid, ?int $sourceId, ?string $feedId, User $by): void
    {
        $sub = $this->find($ulid);
        if (! in_array($sub->status, ['sent', 'mls_review'], true)) {
            throw ValidationException::withMessages(['status' => 'Only a board that was sent to the MLS can go live.']);
        }
        if ($sub->member_id === null) {
            throw ValidationException::withMessages(['status' => 'The brokerage has not given its MLS member ID yet.']);
        }
        $board = $this->db()->table('idx_boards')->where('id', $sub->board_id)->first();
        $source = $sourceId !== null ? MlsSource::query()->whereKey($sourceId)->first() : null;
        if ($source === null && $sub->own_key !== null && $board !== null) {
            $source = $this->ownFeed($sub, $board);
        }
        $source ??= $board?->mls_source_id !== null ? MlsSource::query()->whereKey((int) $board->mls_source_id)->first() : null;
        if ($source === null) {
            throw ValidationException::withMessages(['source' => 'Choose the feed for this board (HQ › MLS connections), or link it to the board first.']);
        }
        if ($source->locked_tenant_id !== null && $source->locked_tenant_id !== (int) $sub->tenant_id) {
            throw ValidationException::withMessages(['source' => 'That feed belongs to another brokerage.']);
        }
        $auth = $this->access->request((int) $sub->tenant_id, $source, (string) $sub->member_id, array_filter([(string) $sub->office_id]), 'IDX '.$ulid, $by);
        $this->access->approve($auth, ['idx'], null, 'IDX '.$ulid, $by);
        FooterDisclosures::forget((int) $sub->tenant_id); // SU2: the website footer shows the board at once
        $this->db()->table('idx_subscriptions')->where('id', $sub->id)->update(['status' => 'live', 'mls_authorization_id' => $auth->id,
            'feed_id' => $feedId !== null && trim($feedId) !== '' ? mb_substr(trim($feedId), 0, 120) : $sub->feed_id, 'client_note' => null, 'status_at' => now(), 'live_at' => now(), 'updated_at' => now()]);
        DemoListings::forget((int) $sub->tenant_id);
        $this->audit->record('idx.live', metadata: ['subscription' => $ulid, 'source' => $source->key, 'by' => $by->id], severity: 'critical', tenantId: (int) $sub->tenant_id);
        $this->tell($sub, 'Your MLS listings are live', ['Real listings from '.($board->name ?? 'your MLS board').' now show on your website, with the MLS\'s required notices.',
            'Demo listings for this board are switched off.'], 'See your MLS boards');
    }

    /** Cancel a board: its listings disappear from the brokerage's website at once. */
    public function cancel(string $ulid, ?int $tenantId, User $by): void
    {
        $sub = $this->find($ulid);
        if ($tenantId !== null && (int) $sub->tenant_id !== $tenantId) {
            abort(404);
        }
        if ($sub->status === 'cancelled') {
            return;
        }
        if ($sub->mls_authorization_id !== null && ($auth = MlsAuthorization::query()->whereKey((int) $sub->mls_authorization_id)->first()) !== null && $auth->status === 'active') {
            $this->access->revoke($auth, 'IDX board cancelled', $by);
        }
        FooterDisclosures::forget((int) $sub->tenant_id); // SU2: the website footer shows the board at once
        $this->db()->table('idx_subscriptions')->where('id', $sub->id)->update(['status' => 'cancelled', 'status_at' => now(), 'cancelled_at' => now(), 'updated_at' => now()]);
        DemoListings::forget((int) $sub->tenant_id);
        $this->audit->record('idx.cancelled', metadata: ['subscription' => $ulid, 'by' => $by->id, 'by_brokerage' => $tenantId !== null], severity: 'warning', tenantId: (int) $sub->tenant_id);
    }

    /** Daily: a gentle reminder to sign, 1 and 3 days after the details were sent (then it stops). */
    public function remind(): int
    {
        $sent = 0;
        foreach ($this->db()->table('idx_subscriptions')->where('status', 'awaiting_signature')->where('reminders', '<', count(self::REMIND_AFTER_DAYS))->get() as $sub) {
            $due = CarbonImmutable::parse((string) $sub->status_at)->addDays(self::REMIND_AFTER_DAYS[(int) $sub->reminders]);
            if ($due->isFuture()) {
                continue;
            }
            $board = $this->db()->table('idx_boards')->where('id', $sub->board_id)->value('name');
            $this->tell($sub, 'Reminder: please sign your MLS agreement', ['The agreement to show '.$board.' listings on your website is waiting for a signature.',
                'Once it is signed, RightAlly sends it to the MLS for you.'], 'Open my MLS boards', true);
            $this->db()->table('idx_subscriptions')->where('id', $sub->id)->update(['reminders' => (int) $sub->reminders + 1, 'updated_at' => now()]);
            $sent++;
        }

        return $sent;
    }

    private function ownFeed(stdClass $sub, stdClass $board): MlsSource
    {
        $connector = (string) ($sub->connector ?? $board->main_connector ?? 'direct_reso');
        $provider = in_array($connector, MlsSource::PROVIDERS, true) ? $connector : 'direct_reso';
        $base = MlsSource::PRESETS[$provider]['base_url'] ?? null;
        if ($base === null) {
            throw ValidationException::withMessages(['source' => 'Add a feed for this brokerage\'s own key in HQ › MLS connections.']);
        }
        $key = 'own-'.strtolower((string) $board->code).'-'.$sub->tenant_id;

        return MlsSource::query()->firstOrCreate(['key' => $key], ['name' => $board->name.' (brokerage\'s own key)', 'states' => [(string) $board->state], 'provider' => $provider,
            'base_url' => $base, 'originating_system' => $provider === 'repliers' ? $board->connector_code : null, 'credentials' => ['token' => Crypt::decryptString((string) $sub->own_key)],
            'status' => 'active', 'locked_tenant_id' => (int) $sub->tenant_id, 'rules_profile_id' => $board->rules_profile_id,
            'sync_interval_minutes' => $provider === 'spark' ? MlsSource::SPARK_MIN_INTERVAL : 15, 'rate_limit_per_hour' => 1000, 'timezone' => 'America/Chicago']);
    }

    /**
     * @param  list<string>  $lines
     */
    private function tell(stdClass $sub, string $heading, array $lines, string $button, bool $alsoManagingBroker = false): void
    {
        try {
            $tenant = Tenant::query()->whereKey((int) $sub->tenant_id)->first();
            if ($tenant === null) {
                return;
            }
            $to = $this->tenants->runAs($tenant, fn () => User::query()->where('kind', UserKind::Owner)->pluck('email')->all());
            if ($alsoManagingBroker && is_string($sub->managing_broker_email)) {
                $to[] = $sub->managing_broker_email;
            }
            $to = array_values(array_unique(array_filter($to, fn ($e): bool => is_string($e) && filter_var($e, FILTER_VALIDATE_EMAIL) !== false)));
            if ($to !== []) {
                Mail::to($to)->queue(new IdxUpdateMail($heading, $lines, $button, 'https://'.Host::platformHostFor($tenant->slug).'/settings/mls'));
            }
        } catch (Throwable) {
            // An email that cannot go out never stops the board's progress.
        }
    }

    private function find(string $ulid): stdClass
    {
        $sub = $this->db()->table('idx_subscriptions')->where('ulid', $ulid)->first();
        if (! $sub instanceof stdClass) {
            abort(404);
        }

        return $sub;
    }

    private function own(int $tenantId, string $ulid): stdClass
    {
        $sub = $this->find($ulid);
        if ((int) $sub->tenant_id !== $tenantId) {
            abort(404);
        }

        return $sub;
    }

    /**
     * @return list<array{key: string, label: string, type: string, value: string, required: bool, filled_by_us: bool}>
     */
    private static function formRows(stdClass $board, stdClass $s): array
    {
        $values = BoardForm::values($board, $s);
        $out = [];
        foreach (BoardForm::fields($board) as $f) {
            if ($f['source'] !== null && $f['source'] !== 'skip') {
                $out[] = ['key' => $f['key'], 'label' => $f['label'], 'type' => $f['type'] === 'date' ? 'date' : ($f['type'] === 'checkbox' ? 'checkbox' : 'text'),
                    'value' => $values[$f['key']] ?? '', 'required' => $f['required'], 'filled_by_us' => $f['source'] !== 'fill'];
            }
        }

        return $out;
    }

    /** @return array<string, mixed> */
    private function describe(stdClass $s, ?stdClass $board, bool $hq): array
    {
        $out = ['ulid' => (string) $s->ulid, 'board' => (string) ($board->name ?? 'Unknown board'), 'board_code' => (string) ($board->code ?? ''),
            'state' => (string) ($board->state ?? ''), 'status' => (string) $s->status, 'status_label' => self::STATUSES[$s->status] ?? $s->status,
            'since' => $s->status_at !== null ? CarbonImmutable::parse((string) $s->status_at)->toIso8601String() : null, 'note' => $s->client_note,
            'member_id' => $s->member_id, 'office_id' => $s->office_id, 'license_number' => $s->license_number, 'managing_broker_name' => $s->managing_broker_name,
            'managing_broker_email' => $s->managing_broker_email, 'has_own_key' => $s->own_key !== null, 'fee_cents' => $s->fee_cents !== null ? (int) $s->fee_cents : null,
            'approval' => $board !== null ? $board->approval_days_min.'–'.$board->approval_days_max.' business days' : null,
            // IDX6: the agreement and the nightly website check.
            'agreement' => match (true) {
                ($s->sent_to_mls_at ?? null) !== null => 'Signed and sent to the MLS',
                ($s->signed_at ?? null) !== null => 'Signed',
                ($s->packet_ulid ?? null) !== null => 'Out for signature',
                default => null,
            },
            'has_copy' => ($s->signed_path ?? null) !== null, 'website' => $s->website ?? null,
            'compliance_ok' => isset($s->compliance_ok) ? (bool) $s->compliance_ok : null, 'compliance_note' => $s->compliance_note ?? null];
        // IDX12: the board's own form, filled in, for the brokerage to review (signature lines are signed later).
        $out['form'] = $board !== null && BoardForm::inUse($board) ? self::formRows($board, $s) : null;
        if ($hq) {
            // IDX11: the check of the signed agreement (rules, then AI), for HQ to send on or send back.
            $out['check'] = ($s->checked_at ?? null) !== null ? ['result' => (string) $s->check_result, 'source' => (string) $s->check_source,
                'issues' => (array) json_decode((string) ($s->check_issues ?? '[]'), true), 'note' => $s->check_note,
                'at' => CarbonImmutable::parse((string) $s->checked_at)->toIso8601String()] : null;
            $out['form_version'] = ($s->agreement_version ?? null) !== null ? (int) $s->agreement_version : null;
        }
        if ($hq) {
            $out += ['connector' => $s->connector, 'feed_id' => $s->feed_id, 'mls_source_id' => $board?->mls_source_id !== null ? (int) $board->mls_source_id : null,
                'reminders' => (int) $s->reminders, 'submitted_at' => $s->submitted_at];
        }

        return $out;
    }
}
