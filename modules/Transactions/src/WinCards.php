<?php

declare(strict_types=1);

namespace Modules\Transactions;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Core\Audit\AuditLogger;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\TenantContext;
use Modules\Crm\FairHousing;
use Modules\Sites\SiteSetup;
use Modules\Transactions\Models\Deal;
use stdClass;
use Throwable;

/**
 * Win cards (RF1, specs/transactions/32): a "Just sold" or "Just closed" page on the brokerage website for a closed
 * deal, shared to social media in one tap. Made only after the agent confirms the client agreed. Shows the address
 * and city, the agent and the brokerage; the price only if the agent chooses. Never the client's name. Can be hidden
 * at any time.
 */
final class WinCards
{
    public function __construct(private readonly TenantContext $tenants) {}

    public function make(Deal $deal, User $by, bool $clientAgreed, ?string $message, bool $showPrice): string
    {
        if ($deal->status !== 'closed') {
            throw ValidationException::withMessages(['win' => 'Win cards are for closed deals.']);
        }
        if (! $clientAgreed) {
            throw ValidationException::withMessages(['client_agreed' => 'Tick that your client agreed to sharing this.']);
        }
        $message = trim((string) $message) !== '' ? trim((string) $message) : self::defaultMessage($deal);
        if (($flags = FairHousing::screen($message, 'websites', 'blocked', 'Win card')) !== []) {
            throw ValidationException::withMessages(['message' => 'Fair Housing: reword "'.implode('", "', $flags).'".']);
        }
        $db = DB::connection(Connections::cell())->table('tx_win_cards');
        $existing = $db->clone()->where('tenant_id', $this->tenants->id())->where('deal_id', $deal->id)->first();
        $row = ['headline' => mb_substr(self::headline($deal), 0, 120), 'message' => mb_substr($message, 0, 400), 'show_price' => $showPrice, 'status' => 'live',
            'consent_by' => $by->id, 'consent_at' => now(), 'updated_at' => now()];
        if ($existing !== null) {
            $db->clone()->where('id', $existing->id)->update($row);
            $ulid = (string) $existing->ulid;
        } else {
            $ulid = (string) Str::ulid();
            $db->clone()->insert($row + ['ulid' => $ulid, 'tenant_id' => $this->tenants->id(), 'deal_id' => $deal->id, 'agent_user_id' => $deal->agent_user_id, 'created_at' => now()]);
        }
        app(AuditLogger::class)->record('transactions.win_card.shared', $deal, metadata: ['card' => $ulid, 'price' => $showPrice], label: $deal->street);

        return $ulid;
    }

    public function hide(Deal $deal, User $by): void
    {
        DB::connection(Connections::cell())->table('tx_win_cards')->where('tenant_id', $this->tenants->id())->where('deal_id', $deal->id)
            ->update(['status' => 'hidden', 'updated_at' => now()]);
        app(AuditLogger::class)->record('transactions.win_card.hidden', $deal, label: $deal->street);
    }

    /**
     * What the deal page shows: whether the brokerage website is live, the card if there is one, and its link.
     *
     * @return array{website: bool, card: array{status: string, message: string, show_price: bool, views: int, url: string|null}|null, suggested: string}
     */
    public function forDeal(Deal $deal): array
    {
        $row = $this->find($deal);
        $host = $this->host();

        return ['website' => $host !== null, 'suggested' => self::defaultMessage($deal), 'card' => $row === null ? null : ['status' => (string) $row->status,
            'message' => (string) $row->message, 'show_price' => (bool) $row->show_price, 'views' => (int) $row->views,
            'url' => $host !== null ? 'https://'.$host.'/wins/'.$row->ulid : null]];
    }

    public function find(Deal $deal): ?stdClass
    {
        return DB::connection(Connections::cell())->table('tx_win_cards')->where('tenant_id', $this->tenants->id())->where('deal_id', $deal->id)->first();
    }

    private function host(): ?string
    {
        try {
            return app(SiteSetup::class)->liveHost();
        } catch (Throwable) {
            return null;
        }
    }

    public static function headline(Deal $deal): string
    {
        return ($deal->type === 'buyer' ? 'Just closed: ' : 'Just sold: ').$deal->street.($deal->city !== null && $deal->city !== '' ? ', '.$deal->city : '');
    }

    public static function defaultMessage(Deal $deal): string
    {
        return $deal->type === 'buyer'
            ? "Congratulations to my clients on their new home at {$deal->street}! It was a pleasure helping you find it."
            : "Sold! Thank you to my clients for trusting me with {$deal->street}. Thinking of selling? I'd love to help.";
    }
}
