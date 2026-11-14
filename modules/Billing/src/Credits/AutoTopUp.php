<?php

declare(strict_types=1);

namespace Modules\Billing\Credits;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Billing\Gateway\PaymentGateway;
use Modules\Core\Audit\AuditLogger;
use Modules\Core\Identity\Enums\UserKind;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\TenantContext;

/**
 * Saved cards and automatic top-up (specs/billing/05). The card is saved at a checkout; afterwards credits can be
 * bought in one click, and when the balance drops below the owner's threshold the chosen pack is bought on the saved
 * card automatically. Works for the brokerage pool (owners and staff) and for an agent's own credits.
 */
final class AutoTopUp
{
    /** Never try more often than this, and stop after this many failures in a row until the card is fixed. */
    public const COOLDOWN_MINUTES = 10;

    public const MAX_FAILURES = 3;

    public function __construct(private readonly TenantContext $tenants, private readonly PaymentGateway $gateway, private readonly CreditLedger $ledger) {}

    /**
     * @return array{card: array{brand: string, last4: string}|null, enabled: bool, threshold: int, pack: string|null, failures: int, last_error: string|null}
     */
    public function settings(?int $userId): array
    {
        $row = $this->row($userId);
        $pack = $row?->pack_id !== null ? DB::connection(Connections::control())->table('credit_packs')->where('id', $row->pack_id)->value('ulid') : null;

        return ['card' => $row !== null && $row->customer_ref !== null && $row->card_last4 !== null ? ['brand' => (string) $row->card_brand, 'last4' => (string) $row->card_last4] : null,
            'enabled' => (bool) ($row->enabled ?? false), 'threshold' => (int) ($row->threshold ?? 200), 'pack' => is_string($pack) ? $pack : null,
            'failures' => (int) ($row->failures ?? 0), 'last_error' => $row?->last_error !== null ? (string) $row->last_error : null];
    }

    public function customer(?int $userId): ?string
    {
        $ref = $this->row($userId)?->customer_ref;

        return is_string($ref) && $ref !== '' ? $ref : null;
    }

    /** Remembers the card a buyer saved at checkout. */
    public function rememberCard(?int $userId, string $customerRef): void
    {
        if ($customerRef === '') {
            return;
        }
        $card = $this->gateway->savedCard($customerRef);
        $this->upsert($userId, ['customer_ref' => $customerRef, 'card_brand' => $card['brand'] ?? null, 'card_last4' => $card['last4'] ?? null, 'failures' => 0, 'last_error' => null]);
    }

    public function save(?int $userId, bool $enabled, int $threshold, ?string $packUlid, User $by): void
    {
        $pack = $packUlid !== null ? DB::connection(Connections::control())->table('credit_packs')->where('ulid', $packUlid)->where('active', true)->first() : null;
        if ($enabled && ($this->customer($userId) === null || $pack === null)) {
            throw ValidationException::withMessages(['pack' => 'Save a card (buy a pack once) and choose which pack to buy automatically.']);
        }
        $this->upsert($userId, ['enabled' => $enabled, 'threshold' => max(0, min($threshold, 10_000_000)), 'pack_id' => $pack->id ?? null, 'failures' => 0, 'last_error' => null,
            'updated_by' => $by->id]);
        app(AuditLogger::class)->record('billing.credits.autopay_saved', metadata: ['user' => $userId, 'enabled' => $enabled, 'threshold' => $threshold, 'pack' => $packUlid], severity: 'notice');
    }

    public function removeCard(?int $userId, User $by): void
    {
        $this->upsert($userId, ['customer_ref' => null, 'card_brand' => null, 'card_last4' => null, 'enabled' => false, 'updated_by' => $by->id]);
        app(AuditLogger::class)->record('billing.credits.card_removed', metadata: ['user' => $userId], severity: 'notice');
    }

    /** After credits are spent: buys the chosen pack on the saved card when the balance is below the threshold. */
    public function check(?int $userId): void
    {
        $row = $this->row($userId);
        if ($row === null || ! $row->enabled || $row->customer_ref === null || $row->pack_id === null || (int) $row->failures >= self::MAX_FAILURES
            || ($row->last_attempt_at !== null && now()->subMinutes(self::COOLDOWN_MINUTES)->lt($row->last_attempt_at))
            || $this->ledger->balanceOf($userId) >= (int) $row->threshold) {
            return;
        }
        $this->upsert($userId, ['last_attempt_at' => now()]);
        $this->charge($userId, (int) $row->pack_id, 'auto', null);
    }

    /**
     * Buys a pack on the saved card now (one-click buy, or the automatic top-up).
     *
     * @return string succeeded, processing, requires_action or failed
     */
    public function charge(?int $userId, int $packId, string $source, ?User $buyer): string
    {
        $customer = $this->customer($userId) ?? throw ValidationException::withMessages(['pack' => 'There is no saved card yet.']);
        $pack = DB::connection(Connections::control())->table('credit_packs')->where('id', $packId)->first() ?? throw ValidationException::withMessages(['pack' => 'That pack is no longer sold.']);
        $ulid = (string) Str::ulid();
        $buyerId = $buyer->id ?? (int) ($this->row($userId)->updated_by ?? 0);
        DB::connection(Connections::cell())->table('credit_purchases')->insert(['ulid' => $ulid, 'tenant_id' => $this->tenants->id(), 'account_user_id' => $userId,
            'buyer_id' => $buyerId, 'pack_id' => (int) $pack->id, 'pack_name' => (string) $pack->name, 'credits' => (int) $pack->credits, 'price_cents' => (int) $pack->price_cents,
            'status' => 'pending', 'source' => $source, 'created_at' => now(), 'updated_at' => now()]);
        $result = $this->gateway->chargeSavedCard($customer, (int) $pack->price_cents,
            'RightAlly credits: '.$pack->name.' ('.number_format((int) $pack->credits).' credits)'.($source === 'auto' ? ', automatic top-up' : ''),
            ['kind' => 'credit_pack', 'tenant_id' => (string) $this->tenants->id(), 'purchase' => $ulid], 'credits:'.$ulid);

        if ($result['status'] === 'succeeded') {
            app(CreditPurchases::class)->paid($ulid, ['payment_intent' => $result['payment'], 'amount_total' => (int) $pack->price_cents]);
            $this->upsert($userId, ['failures' => 0, 'last_error' => null]);
        } elseif ($result['status'] !== 'processing') {
            DB::connection(Connections::cell())->table('credit_purchases')->where('ulid', $ulid)->update(['status' => 'failed', 'updated_at' => now()]);
            $this->failed($userId, (string) ($result['error'] ?? 'The card was declined.'));
        }

        return $result['status'];
    }

    /** A saved-card payment failed (now, or later from the payment provider): tell the people who can fix it. */
    public function failed(?int $userId, string $error): void
    {
        $row = $this->row($userId);
        $this->upsert($userId, ['failures' => (int) ($row->failures ?? 0) + 1, 'last_error' => mb_substr($error, 0, 200)]);
        $to = $userId !== null ? User::query()->whereKey($userId)->get() : User::query()->where('kind', UserKind::Owner)->get();
        Notification::send($to->filter(fn (User $u): bool => $u->isActive()), new CardNeedsAttention($error, $userId === null));
    }

    private function row(?int $userId): ?\stdClass
    {
        $row = DB::connection(Connections::cell())->table('credit_autopay')->where('tenant_id', $this->tenants->id())
            ->where(fn ($q) => $userId === null ? $q->whereNull('user_id') : $q->where('user_id', $userId))->first();

        return $row instanceof \stdClass ? $row : null;
    }

    /** @param  array<string, mixed>  $values */
    private function upsert(?int $userId, array $values): void
    {
        $db = DB::connection(Connections::cell())->table('credit_autopay');
        $where = fn ($q) => $q->where('tenant_id', $this->tenants->id())->where(fn ($w) => $userId === null ? $w->whereNull('user_id') : $w->where('user_id', $userId));
        if ((clone $db)->where($where)->exists()) {
            (clone $db)->where($where)->update($values + ['updated_at' => now()]);
        } else {
            (clone $db)->insert($values + ['tenant_id' => $this->tenants->id(), 'user_id' => $userId, 'created_at' => now(), 'updated_at' => now()]);
        }
    }
}
