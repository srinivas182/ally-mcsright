<?php

declare(strict_types=1);

namespace Modules\Transactions\Offers;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Core\Audit\AuditLogger;
use Modules\Core\Support\Connections;
use Modules\Forms\Guard;
use Modules\Transactions\DealIq\Adapt;
use Modules\Transactions\DealIq\FormDrafter;
use Modules\Transactions\Documents;
use Modules\Transactions\Models\Deal;
use Modules\Transactions\Playbooks\Playbooks;

/**
 * Offers on a listing (specs/ai/02, §16). Buyers' agents send theirs through the listing's offer link (no account),
 * or the agent uploads one and Deal-IQ reads it. Rounds ("highest and best"), counters and the decision are sent to
 * every buyer's agent at the same moment with the same words, and logged as evidence of fair treatment.
 */
final class Offers
{
    public const LINK_DAYS = 60;

    /** Terms a buyer's agent or the listing agent can set. */
    public const TERMS = ['agent_name', 'agent_email', 'agent_phone', 'brokerage', 'buyer_names', 'price_cents', 'financing', 'down_percent', 'earnest_cents', 'option_fee_cents',
        'option_days', 'concessions_cents', 'buyer_agent_cents', 'closing_date', 'contingencies', 'appraisal_gap_cents', 'escalation_cap_cents', 'escalation_step_cents', 'proof',
        'leaseback_days', 'expires_at', 'notes'];

    public static function forListing(Deal $deal): bool
    {
        return in_array($deal->type, ['listing', 'dual', 'new_construction'], true);
    }

    /** A new offer link for the listing (the old one stops working). */
    public function link(Deal $deal, User $by): string
    {
        if (! self::forListing($deal) || $deal->locked_at !== null) {
            throw ValidationException::withMessages(['link' => 'Offers are collected on open listings.']);
        }
        $db = DB::connection(Connections::cell());
        $db->table('tx_offer_links')->where('deal_id', $deal->id)->whereNull('revoked_at')->update(['revoked_at' => now(), 'updated_at' => now()]);
        $token = Str::random(48);
        $db->table('tx_offer_links')->insert(['tenant_id' => $deal->tenant_id, 'deal_id' => $deal->id, 'token_hash' => hash('sha256', $token), 'created_by' => $by->id,
            'expires_at' => CarbonImmutable::now()->addDays(self::LINK_DAYS), 'created_at' => now(), 'updated_at' => now()]);
        app(AuditLogger::class)->record('transactions.offers.link', $deal, label: $deal->street);

        return $token;
    }

    /** The listing behind an offer link, while it works. */
    public function dealForLink(string $token): ?Deal
    {
        $row = DB::connection(Connections::cell())->table('tx_offer_links')->where('token_hash', hash('sha256', $token))->whereNull('revoked_at')->where('expires_at', '>', now())->first();
        $deal = $row !== null ? Deal::query()->whereKey((int) $row->deal_id)->first() : null;

        return $deal !== null && $deal->locked_at === null && in_array($deal->status, ['pre_contract', 'under_contract'], true) ? $deal : null;
    }

    /**
     * A buyer's agent sends an offer through the link; they get a private link to revise it.
     *
     * @param  array<string, mixed>  $terms
     * @return array{offer: \stdClass, update: string}
     */
    public function submit(Deal $deal, array $terms, ?UploadedFile $file, string $source, ?User $by = null): array
    {
        $terms = self::clean($terms);
        if ($source === 'link' && ((int) ($terms['price_cents'] ?? 0) <= 0 || ($terms['agent_email'] ?? null) === null || ($terms['agent_name'] ?? null) === null)) {
            throw ValidationException::withMessages(['price' => 'Give the price and your name and email.']);
        }
        $db = DB::connection(Connections::cell());
        $count = $db->table('tx_offers')->where('deal_id', $deal->id)->count();
        $update = Str::random(48);
        $id = $db->table('tx_offers')->insertGetId($terms + ['ulid' => (string) Str::ulid(), 'tenant_id' => $deal->tenant_id, 'deal_id' => $deal->id,
            'label' => self::label($count), 'source' => $source, 'status' => 'received', 'update_hash' => hash('sha256', $update), 'submitted_at' => now(),
            'created_at' => now(), 'updated_at' => now()]);
        if ($file !== null) {
            $this->attach($id, $file);
        }
        $offer = $db->table('tx_offers')->where('id', $id)->first() ?? throw new \LogicException('offer');
        app(AuditLogger::class)->record('transactions.offers.received', $deal, metadata: ['offer' => $offer->label, 'source' => $source, 'price_cents' => $offer->price_cents], label: $deal->street);
        if ($source === 'link') {
            User::query()->whereKey($deal->agent_user_id)->first()?->notify(new OfferReceivedMail($deal->street, 'Offer '.$offer->label, (string) $offer->agent_name, (int) $offer->price_cents, '/deals/'.$deal->ulid.'/offers'));
            Notification::route('mail', (string) $offer->agent_email)->notify(new OfferNoticeMail($deal->street,
                'Your offer on '.$deal->street.' was received. Use the link below to revise it while offers are being taken.', null, url('/offer/revise/'.$update)));
        }

        return ['offer' => $offer, 'update' => $update];
    }

    /** The offer behind a buyer's agent's revise link, while it can still be changed. */
    public function forUpdate(string $token): ?\stdClass
    {
        $o = DB::connection(Connections::cell())->table('tx_offers')->where('update_hash', hash('sha256', $token))->first();
        if ($o === null || ! in_array($o->status, ['received', 'shortlisted', 'countered'], true)) {
            return null;
        }
        $deadline = $this->openDeadline((int) $o->deal_id);

        return $deadline !== null && $deadline->isPast() ? null : $o;
    }

    /**
     * The buyer's agent revises (a new round when a deadline is open). The earlier terms are kept.
     *
     * @param  array<string, mixed>  $terms
     */
    public function revise(\stdClass $offer, array $terms, ?UploadedFile $file, ?User $by = null): void
    {
        $terms = self::clean($terms);
        unset($terms['agent_email']); // the address the link was sent to stays
        if ($by === null) { // the buyer's agent revising: who they are stays as sent
            foreach (['agent_name', 'agent_phone', 'brokerage', 'buyer_names'] as $k) {
                if (($terms[$k] ?? null) === null) {
                    unset($terms[$k]);
                }
            }
        }
        $db = DB::connection(Connections::cell());
        $before = array_intersect_key((array) $offer, array_flip(self::TERMS));
        $history = (array) json_decode((string) ($offer->history ?? '[]'), true);
        $history[] = ['round' => (int) $offer->round, 'at' => now()->toIso8601String(), 'terms' => $before];
        $round = $this->openDeadline((int) $offer->deal_id) !== null ? (int) $db->table('tx_offer_notices')->where('deal_id', $offer->deal_id)->where('kind', 'highest_best')->count() + 1 : (int) $offer->round;
        $db->table('tx_offers')->where('id', $offer->id)->update($terms + ['history' => json_encode(array_slice($history, -10)), 'round' => $round, 'updated_at' => now()]);
        if ($file !== null) {
            $this->attach((int) $offer->id, $file);
        }
        $deal = Deal::query()->whereKey((int) $offer->deal_id)->firstOrFail();
        app(AuditLogger::class)->record('transactions.offers.revised', $deal, metadata: ['offer' => $offer->label, 'by' => $by !== null ? 'listing agent' : 'buyer agent'], label: $deal->street);
        if ($by === null) {
            User::query()->whereKey($deal->agent_user_id)->first()?->notify(new OfferReceivedMail($deal->street, 'Offer '.$offer->label.' (revised)', (string) $offer->agent_name,
                (int) ($terms['price_cents'] ?? $offer->price_cents), '/deals/'.$deal->ulid.'/offers'));
        }
    }

    public function status(Deal $deal, string $offerUlid, string $status, User $by): void
    {
        $offer = $this->find($deal, $offerUlid);
        if (! in_array($status, ['received', 'shortlisted', 'declined', 'withdrawn'], true) || $offer->status === 'accepted') {
            throw ValidationException::withMessages(['status' => 'That can\'t be changed here.']);
        }
        DB::connection(Connections::cell())->table('tx_offers')->where('id', $offer->id)->update(['status' => $status, 'updated_at' => now()]);
        app(AuditLogger::class)->record('transactions.offers.status', $deal, metadata: ['offer' => $offer->label, 'status' => $status], label: $deal->street);
    }

    /**
     * The same words to every buyer's agent with an open offer, at the same moment, logged.
     *
     * @return int how many agents were told
     */
    public function notify(Deal $deal, string $kind, string $message, ?CarbonImmutable $deadline, User $by, ?int $exceptOfferId = null): int
    {
        $message = trim($message);
        Guard::noWiringInstructions($message, 'message');
        if ($message === '' || ! in_array($kind, ['multiple', 'highest_best', 'decision'], true)) {
            throw ValidationException::withMessages(['message' => 'Write the message.']);
        }
        if ($kind === 'highest_best' && ($deadline === null || $deadline->isPast())) {
            throw ValidationException::withMessages(['deadline' => 'Give a deadline in the future.']);
        }
        $db = DB::connection(Connections::cell());
        $offers = $db->table('tx_offers')->where('deal_id', $deal->id)->whereIn('status', ['received', 'shortlisted', 'countered'])->whereNotNull('agent_email')
            ->when($exceptOfferId !== null, fn ($q) => $q->where('id', '!=', $exceptOfferId))->get();
        $now = now();
        $recipients = [];
        foreach ($offers as $o) {
            $update = Str::random(48);
            $db->table('tx_offers')->where('id', $o->id)->update(['update_hash' => hash('sha256', $update), 'updated_at' => $now]);
            Notification::route('mail', (string) $o->agent_email)->notify(new OfferNoticeMail($deal->street, $message, $deadline, $kind === 'decision' ? null : url('/offer/revise/'.$update)));
            $recipients[] = ['offer' => 'Offer '.$o->label, 'email' => AuditLogger::mask((string) $o->agent_email)];
        }
        $db->table('tx_offer_notices')->insert(['tenant_id' => $deal->tenant_id, 'deal_id' => $deal->id, 'kind' => $kind, 'message' => mb_substr($message, 0, 3000),
            'deadline_at' => $deadline, 'recipients' => json_encode($recipients), 'sent_by' => $by->id, 'sent_at' => $now]);
        app(AuditLogger::class)->record('transactions.offers.notice', $deal, metadata: ['kind' => $kind, 'to' => count($recipients), 'deadline' => $deadline?->toIso8601String()], label: $deal->street);

        return count($recipients);
    }

    /**
     * Counter-offer: drafted on the brokerage's own form for the agent to check and send for signature.
     *
     * @param  array{price_cents?: int|null, closing_date?: string|null, concessions_cents?: int|null, note?: string|null}  $changes
     */
    public function counter(Deal $deal, string $offerUlid, array $changes, User $by): ?string
    {
        $offer = $this->find($deal, $offerUlid);
        $parts = array_filter([
            isset($changes['price_cents']) ? 'price $'.number_format($changes['price_cents'] / 100) : null,
            isset($changes['closing_date']) ? 'closing on '.$changes['closing_date'] : null,
            isset($changes['concessions_cents']) ? 'seller concessions $'.number_format($changes['concessions_cents'] / 100) : null,
            ($changes['note'] ?? '') !== '' ? (string) $changes['note'] : null,
        ]);
        if ($parts === []) {
            throw ValidationException::withMessages(['counter' => 'Say what the seller is countering.']);
        }
        $draft = app(FormDrafter::class)->draft($deal, 'Seller\'s counter-offer to the offer from '.($offer->agent_name ?? 'the buyer\'s agent').' ('.($offer->buyer_names ?? 'buyers').'): '.implode('; ', $parts).'. All other terms as offered.', $by);
        DB::connection(Connections::cell())->table('tx_offers')->where('id', $offer->id)->update(['status' => 'countered', 'packet' => $draft['packet'] ?? null, 'updated_at' => now()]);
        app(AuditLogger::class)->record('transactions.offers.countered', $deal, metadata: ['offer' => $offer->label, 'drafted' => $draft !== null], label: $deal->street);

        return $draft['packet'] ?? null;
    }

    /**
     * The seller accepts: the deal takes the offer's terms and people, the checklist adapts, and every other buyer's
     * agent is told at once.
     */
    public function accept(Deal $deal, string $offerUlid, User $by): void
    {
        $offer = $this->find($deal, $offerUlid);
        if (! in_array($offer->status, ['received', 'shortlisted', 'countered'], true)) {
            throw ValidationException::withMessages(['offer' => 'This offer is no longer open.']);
        }
        $db = DB::connection(Connections::cell());
        $price = Compare::prices(array_values($db->table('tx_offers')->where('deal_id', $deal->id)->whereIn('status', ['received', 'shortlisted', 'countered'])->get()->all()))[(int) $offer->id] ?? (int) $offer->price_cents;
        $db->transaction(function () use ($db, $deal, $offer, $price): void {
            $db->table('tx_offers')->where('id', $offer->id)->update(['status' => 'accepted', 'updated_at' => now()]);
            $deal->update(['price_cents' => $price, 'closing_date' => $offer->closing_date, 'financing' => array_key_exists((string) $offer->financing, Adapt::FINANCING) ? $offer->financing : $deal->financing]);
            if ($offer->agent_name !== null) {
                $db->table('tx_parties')->insert(['tenant_id' => $deal->tenant_id, 'deal_id' => $deal->id, 'role' => 'co_op_agent', 'name' => $offer->agent_name, 'company' => $offer->brokerage,
                    'email' => $offer->agent_email, 'phone' => $offer->agent_phone, 'created_at' => now(), 'updated_at' => now()]);
            }
            foreach (array_slice(array_filter(array_map('trim', preg_split('/\s*(?:,|\band\b|&)\s*/i', (string) $offer->buyer_names) ?: [])), 0, 4) as $name) {
                $db->table('tx_parties')->insert(['tenant_id' => $deal->tenant_id, 'deal_id' => $deal->id, 'role' => 'buyer', 'name' => mb_substr($name, 0, 160), 'created_at' => now(), 'updated_at' => now()]);
            }
        });
        $deal->forceFill(['terms' => json_encode(['option_days' => $offer->option_days !== null ? (int) $offer->option_days : null,
            'earnest_cents' => $offer->earnest_cents !== null ? (int) $offer->earnest_cents : null, 'accepted_on' => CarbonImmutable::today()->toDateString(), 'offer' => $offer->label])])->save();
        app(Adapt::class)->apply($deal->refresh());
        // Listing to contract (specs/ai/02, §18): the playbook's documents and emails start now; introductions are ready to send.
        $playbooks = app(Playbooks::class);
        $playbooks->apply($deal);
        $db->table('tx_playbook_steps')->updateOrInsert(['deal_id' => $deal->id, 'step' => 'introductions'],
            ['tenant_id' => $deal->tenant_id, 'status' => 'ready', 'due_at' => now(), 'updated_at' => now(), 'created_at' => now()]);
        app(AuditLogger::class)->record('transactions.offers.accepted', $deal, metadata: ['offer' => $offer->label, 'price_cents' => $price], label: $deal->street);
        $others = $db->table('tx_offers')->where('deal_id', $deal->id)->whereIn('status', ['received', 'shortlisted', 'countered'])->exists();
        if ($others) {
            $this->notify($deal, 'decision', 'Thank you for your offer on '.$deal->street.'. The seller has accepted another offer. We will let you know if anything changes.', null, $by, (int) $offer->id);
            $db->table('tx_offers')->where('deal_id', $deal->id)->whereIn('status', ['received', 'shortlisted', 'countered'])->update(['status' => 'declined', 'updated_at' => now()]);
        }
    }

    /**
     * @param  array{listing_percent: float, title_percent: float, payoff_cents: int, other_cents: int}  $sheet
     */
    public function saveSheet(Deal $deal, array $sheet, User $by): void
    {
        $deal->forceFill(['net_sheet' => json_encode($sheet)])->save();
        app(AuditLogger::class)->record('transactions.offers.net_sheet', $deal, label: $deal->street);
    }

    public function find(Deal $deal, string $ulid): \stdClass
    {
        return DB::connection(Connections::cell())->table('tx_offers')->where('deal_id', $deal->id)->where('ulid', $ulid)->first() ?? abort(404);
    }

    /**
     * @return list<\stdClass>
     */
    public static function all(Deal $deal): array
    {
        return array_values(DB::connection(Connections::cell())->table('tx_offers')->where('deal_id', $deal->id)->orderBy('id')->get()->all());
    }

    /** The open "highest and best" deadline, if any. */
    public function openDeadline(int $dealId): ?CarbonImmutable
    {
        $n = DB::connection(Connections::cell())->table('tx_offer_notices')->where('deal_id', $dealId)->where('kind', 'highest_best')->orderByDesc('id')->first();

        return $n !== null && $n->deadline_at !== null ? CarbonImmutable::parse((string) $n->deadline_at) : null;
    }

    /**
     * @return list<array{kind: string, message: string, deadline: string|null, to: int, recipients: list<array{offer: string, email: string}>, at: string}>
     */
    public static function notices(Deal $deal): array
    {
        return array_values(DB::connection(Connections::cell())->table('tx_offer_notices')->where('deal_id', $deal->id)->orderByDesc('id')->get()
            ->map(fn ($n): array => ['kind' => (string) $n->kind, 'message' => (string) $n->message, 'deadline' => $n->deadline_at !== null ? CarbonImmutable::parse((string) $n->deadline_at)->toIso8601String() : null,
                'to' => count((array) json_decode((string) $n->recipients, true)), 'recipients' => array_values((array) json_decode((string) $n->recipients, true)),
                'at' => CarbonImmutable::parse((string) $n->sent_at)->toIso8601String()])->all());
    }

    /** The offer's own file, if it has one. */
    public function file(\stdClass $offer): ?string
    {
        return $offer->file_path !== null && Storage::disk('local')->exists((string) $offer->file_path) ? (string) $offer->file_path : null;
    }

    private function attach(int $offerId, UploadedFile $file): void
    {
        $mime = (string) $file->getMimeType();
        if (! in_array($mime, Documents::MIMES, true)) {
            throw ValidationException::withMessages(['file' => 'Upload a PDF, a photo or a Word file.']);
        }
        $path = $file->store('offers', 'local');
        DB::connection(Connections::cell())->table('tx_offers')->where('id', $offerId)->update(['file_path' => $path, 'file_name' => mb_substr($file->getClientOriginalName(), 0, 200), 'updated_at' => now()]);
    }

    private static function label(int $index): string
    {
        $letters = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $index = max(0, $index);

        return $index < 26 ? $letters[$index] : $letters[min(25, intdiv($index, 26) - 1)].$letters[$index % 26];
    }

    /**
     * @param  array<string, mixed>  $t
     * @return array<string, mixed>
     */
    public static function clean(array $t): array
    {
        $out = [];
        foreach (self::TERMS as $k) {
            if (! array_key_exists($k, $t)) {
                continue;
            }
            $v = $t[$k];
            $out[$k] = match (true) {
                $k === 'contingencies' => json_encode(array_values(array_intersect(array_keys(Compare::CONTINGENCIES), (array) $v))),
                $k === 'financing' => array_key_exists((string) $v, Compare::FINANCING) ? $v : null,
                $k === 'proof' => array_key_exists((string) $v, Compare::PROOF) ? $v : null,
                str_ends_with($k, '_cents') => $v === null || $v === '' ? ($k === 'concessions_cents' || $k === 'buyer_agent_cents' || $k === 'price_cents' ? 0 : null) : max(0, (int) $v),
                in_array($k, ['option_days', 'leaseback_days'], true) => $v === null || $v === '' ? null : max(0, min(365, (int) $v)),
                $k === 'down_percent' => $v === null || $v === '' ? null : max(0, min(100, (float) $v)),
                $k === 'expires_at' => $v instanceof \DateTimeInterface ? $v->format('Y-m-d H:i:s') : (is_string($v) && $v !== '' ? CarbonImmutable::parse($v)->format('Y-m-d H:i:s') : null),
                $k === 'agent_email' => is_string($v) && filter_var(trim($v), FILTER_VALIDATE_EMAIL) ? mb_strtolower(trim($v)) : null,
                default => is_string($v) && trim($v) !== '' ? mb_substr(trim($v), 0, $k === 'notes' ? 1000 : 300) : null,
            };
        }
        if (isset($out['notes'])) {
            Guard::noWiringInstructions((string) $out['notes'], 'notes');
        }

        return $out;
    }
}
