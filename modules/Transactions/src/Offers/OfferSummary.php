<?php

declare(strict_types=1);

namespace Modules\Transactions\Offers;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\AI\AiGateway;
use Modules\AI\Data\AiTask;
use Modules\Core\Audit\AuditLogger;
use Modules\Core\Support\Connections;
use Modules\Forms\Guard;
use Modules\Transactions\Models\Deal;
use Throwable;

/**
 * The summary for the seller (specs/ai/02, §16): the comparison in plain words, in English and Spanish. The figures
 * come from Compare; the AI only writes the words, and a summary with any amount not in the comparison is replaced
 * by one written from the figures. The agent edits it and chooses when the sellers see it in the deal portal.
 */
final class OfferSummary
{
    public const FEATURE = 'docs.offer_summary';

    public function __construct(private readonly AiGateway $gateway) {}

    /**
     * @return array{en: string, es: string, ai: bool}
     */
    public function write(Deal $deal, User $by): array
    {
        $rows = array_values(array_filter(Compare::table($deal, Offers::all($deal), false), fn (array $r): bool => in_array($r['status'], ['received', 'shortlisted', 'countered'], true)));
        if ($rows === []) {
            throw ValidationException::withMessages(['summary' => 'There are no open offers to compare.']);
        }
        $facts = array_map(fn (array $r): array => ['offer' => $r['label'], 'price' => self::usd($r['effective_cents']), 'escalates_to_cap' => $r['escalation_cap_cents'] !== null ? self::usd($r['escalation_cap_cents']) : null,
            'net_to_seller' => self::usd($r['net']['net_cents']), 'financing' => $r['financing'], 'proof' => $r['proof'], 'closing' => $r['closing_date'],
            'contingencies' => $r['contingencies'], 'seller_concessions' => self::usd($r['net']['concessions_cents']), 'likelihood' => $r['certainty']['grade'],
            'reasons' => $r['certainty']['reasons']], $rows);
        $plain = $this->plain($rows);
        $out = ['en' => $plain['en'], 'es' => $plain['es'], 'ai' => false];
        try {
            $r = $this->gateway->run(new AiTask(self::FEATURE, 'transactions.offer_summary', 'offer-summary:'.$deal->ulid.':'.CarbonImmutable::now()->format('YmdHisv'),
                inputs: ['offers' => (string) json_encode($facts)], outputSchema: ['type' => 'object', 'required' => ['en'], 'properties' => ['en' => ['type' => 'string'], 'es' => ['type' => 'string']]],
                userId: $by->id));
            $en = trim((string) ($r->json['en'] ?? ''));
            $es = trim((string) ($r->json['es'] ?? ''));
            $allowed = self::amounts((string) json_encode($facts));
            if ($en !== '' && array_diff(self::amounts($en), $allowed) === [] && array_diff(self::amounts($es), $allowed) === []) {
                $out = ['en' => mb_substr($en, 0, 3000), 'es' => $es !== '' ? mb_substr($es, 0, 3000) : $plain['es'], 'ai' => true];
            }
        } catch (Throwable $e) {
            report($e);
        }
        DB::connection(Connections::cell())->table('tx_offer_summaries')->insert(['tenant_id' => $deal->tenant_id, 'deal_id' => $deal->id, 'text_en' => $out['en'], 'text_es' => $out['es'],
            'offers' => json_encode($rows), 'ai' => $out['ai'], 'created_by' => $by->id, 'created_at' => now(), 'updated_at' => now()]);

        return $out;
    }

    /** The agent edits the words and shares them with the sellers (or stops sharing). */
    public function save(Deal $deal, string $en, ?string $es, bool $share, User $by): void
    {
        $db = DB::connection(Connections::cell());
        $row = $db->table('tx_offer_summaries')->where('deal_id', $deal->id)->orderByDesc('id')->first()
            ?? throw ValidationException::withMessages(['summary' => 'Write the summary first.']);
        Guard::noWiringInstructions($en.' '.$es, 'summary');
        $db->table('tx_offer_summaries')->where('id', $row->id)->update(['text_en' => mb_substr(trim($en), 0, 3000), 'text_es' => $es !== null ? mb_substr(trim($es), 0, 3000) : null,
            'shared_at' => $share ? ($row->shared_at ?? now()) : null, 'offers' => json_encode(array_values(array_filter(Compare::table($deal, Offers::all($deal), false),
                fn (array $r): bool => in_array($r['status'], ['received', 'shortlisted', 'countered', 'accepted'], true)))), 'updated_at' => now()]);
        app(AuditLogger::class)->record($share ? 'transactions.offers.shared' : 'transactions.offers.summary_saved', $deal, label: $deal->street);
    }

    /**
     * The latest summary (for the agent), or the shared one (for the sellers).
     *
     * @return array{en: string, es: string|null, ai: bool, shared: bool, offers: list<array<string, mixed>>, at: string}|null
     */
    public static function latest(Deal $deal, bool $sharedOnly = false): ?array
    {
        $row = DB::connection(Connections::cell())->table('tx_offer_summaries')->where('deal_id', $deal->id)->when($sharedOnly, fn ($q) => $q->whereNotNull('shared_at'))->orderByDesc('id')->first();

        return $row === null ? null : ['en' => (string) $row->text_en, 'es' => $row->text_es !== null ? (string) $row->text_es : null, 'ai' => (bool) $row->ai, 'shared' => $row->shared_at !== null,
            'offers' => array_values((array) json_decode((string) $row->offers, true)), 'at' => CarbonImmutable::parse((string) $row->updated_at)->toIso8601String()];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array{en: string, es: string}
     */
    private function plain(array $rows): array
    {
        $best = $rows[0];
        $safest = collect($rows)->sortByDesc(fn (array $r): int => $r['certainty']['score'])->first() ?? $best;
        $en = count($rows).' offer(s). '.$best['label'].' nets the most: '.self::usd($best['net']['net_cents']).' after costs ('.$best['financing'].', '.$best['certainty']['grade'].' to close).';
        $es = count($rows).' oferta(s). La '.$best['label'].' le deja más: '.self::usd($best['net']['net_cents']).' después de costos ('.$best['financing'].').';
        if ($safest['label'] !== $best['label']) {
            $en .= ' '.$safest['label'].' is the most likely to close and nets '.self::usd($safest['net']['net_cents']).'.';
            $es .= ' La '.$safest['label'].' es la más probable de cerrar y le deja '.self::usd($safest['net']['net_cents']).'.';
        }

        return ['en' => $en, 'es' => str_replace('Offer ', 'Oferta ', $es)];
    }

    private static function usd(int $cents): string
    {
        return '$'.number_format(intdiv($cents, 100));
    }

    /**
     * Dollar amounts in a text, normalised ("$448,500" → 448500).
     *
     * @return list<string>
     */
    private static function amounts(string $text): array
    {
        preg_match_all('/\$\s?(\d[\d,]*)/u', $text, $m);

        return array_values(array_unique(array_map(fn (string $n): string => str_replace(',', '', $n), $m[1])));
    }
}
