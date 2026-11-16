<?php

declare(strict_types=1);

namespace Modules\Transactions\Offers;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Modules\AI\AiGateway;
use Modules\AI\Data\AiTask;
use Modules\Core\Support\Connections;
use Modules\Transactions\Reading\DocIq;
use Modules\Transactions\Reading\PageText;
use Throwable;

/**
 * Reads an uploaded offer (specs/ai/02, §16) into its terms. A term is kept only when its quote is on the cited
 * page and states the value; it fills a blank term only, and is shown with where it was read for the agent to check.
 */
final class OfferReader
{
    public const FEATURE = 'docs.offer_read';

    private const MONEY = ['price' => 'price_cents', 'earnest_money' => 'earnest_cents', 'option_fee' => 'option_fee_cents', 'seller_concessions' => 'concessions_cents',
        'buyer_agent_pay' => 'buyer_agent_cents', 'appraisal_gap' => 'appraisal_gap_cents', 'escalation_cap' => 'escalation_cap_cents', 'escalation_step' => 'escalation_step_cents'];

    private const TEXT = ['agent_name', 'agent_email', 'agent_phone', 'brokerage', 'buyer_names', 'financing'];

    private const YES = ['inspection_contingency' => 'inspection', 'financing_contingency' => 'financing', 'appraisal_contingency' => 'appraisal', 'sale_of_home_contingency' => 'sale_of_home'];

    public function __construct(private readonly AiGateway $gateway) {}

    /** @return int terms read */
    public function read(\stdClass $offer, ?int $userId): int
    {
        if ($offer->file_path === null) {
            return 0;
        }
        $text = PageText::read((string) Storage::disk('local')->get((string) $offer->file_path), (string) Storage::disk('local')->mimeType((string) $offer->file_path));
        $pages = $text['pages'] ?? [];
        if ($pages === [] || implode('', $pages) === '') {
            return 0;
        }
        try {
            $r = $this->gateway->run(new AiTask(self::FEATURE, 'transactions.offer_read', 'offer-read:'.$offer->ulid.':'.md5((string) $offer->file_path),
                untrustedContent: array_map(fn (string $p, int $i): string => '[Page '.($i + 1)."]\n".mb_substr($p, 0, 6000), array_slice($pages, 0, 30), array_keys(array_slice($pages, 0, 30))),
                outputSchema: ['type' => 'object', 'required' => ['terms'], 'properties' => ['terms' => ['type' => 'array']]], userId: $userId));
        } catch (Throwable $e) {
            report($e);

            return 0;
        }
        $update = [];
        $read = [];
        $contingencies = (array) json_decode((string) ($offer->contingencies ?? '[]'), true);
        foreach ((array) ($r->json['terms'] ?? []) as $t) {
            $t = (array) $t;
            $name = (string) ($t['name'] ?? '');
            $value = trim((string) ($t['value'] ?? ''));
            $page = (int) ($t['page'] ?? 0);
            $quote = trim((string) ($t['quote'] ?? ''));
            if ($value === '' || ! DocIq::onPage($quote, $pages[$page - 1] ?? '') || isset($read[$name])) {
                continue;
            }
            $col = null;
            $v = null;
            if (isset(self::MONEY[$name]) && DocIq::states($quote, $value) && ($c = DocIq::centsOf($value)) !== null) {
                [$col, $v] = [self::MONEY[$name], $c];
            } elseif ($name === 'closing_date' || $name === 'expires') {
                $d = DocIq::dateOf($value);
                [$col, $v] = $d !== null ? [$name === 'expires' ? 'expires_at' : 'closing_date', $d] : [null, null];
            } elseif (in_array($name, ['option_days', 'leaseback_days', 'down_percent'], true) && DocIq::states($quote, $value) && preg_match('/\d+(\.\d+)?/', $value, $m) === 1) {
                [$col, $v] = [$name, $name === 'down_percent' ? (float) $m[0] : (int) $m[0]];
            } elseif (in_array($name, self::TEXT, true) && ($name === 'financing' || str_contains(mb_strtolower($quote), mb_strtolower(mb_substr($value, 0, 30))))) {
                [$col, $v] = [$name, $name === 'financing' ? (array_key_exists(mb_strtolower($value), Compare::FINANCING) ? mb_strtolower($value) : null) : mb_substr($value, 0, 160)];
            } elseif (isset(self::YES[$name]) && in_array(mb_strtolower($value), ['yes', 'true'], true)) {
                $contingencies[] = self::YES[$name];
                $read[$name] = ['value' => 'yes', 'page' => $page, 'quote' => mb_substr($quote, 0, 200)];

                continue;
            }
            if ($col === null || $v === null) {
                continue;
            }
            $read[$name] = ['value' => mb_substr($value, 0, 200), 'page' => $page, 'quote' => mb_substr($quote, 0, 200)];
            $current = $offer->{$col} ?? null;
            if ($current === null || $current === '' || ((str_ends_with($col, '_cents')) && (int) $current === 0)) {
                $update[$col] = $v;
            }
        }
        $update['contingencies'] = json_encode(array_values(array_unique(array_filter($contingencies, 'is_string'))));
        $update['read'] = json_encode($read);
        DB::connection(Connections::cell())->table('tx_offers')->where('id', $offer->id)->update($update + ['updated_at' => now()]);

        return count($read);
    }
}
