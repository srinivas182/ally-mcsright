<?php

declare(strict_types=1);

namespace Modules\Compensation;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Modules\AI\AiGateway;
use Modules\AI\Data\AiTask;
use Modules\AI\Exceptions\InsufficientCredits;
use Modules\Compensation\Actions\ManagePlans;
use Modules\Compensation\Enums\DealClass;
use Modules\Compensation\Enums\PlanType;
use Modules\Compensation\Models\Plan;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * AI plan setup (CP1, specs/compensation/03): upload the brokerage's commission plan PDF (or paste its text) and get
 * a draft plan, never a published one, with every number tied to the sentence it came from, and a list of what the
 * document mentioned but could not be turned into a rule. The broker checks it in the plan editor and the simulator,
 * then publishes. The rules go through the same validation as a plan typed by hand.
 */
final class PlanReader
{
    public const FEATURE = 'compensation.plan_reader';

    public const MAX_CHARS = 30000;

    public function __construct(private readonly ManagePlans $plans) {}

    /**
     * @return array{plan: Plan, summary: string, evidence: list<array{rule: string, quote: string}>, unclear: list<string>}
     */
    public function read(?UploadedFile $file, ?string $text, User $by): array
    {
        $source = trim((string) $text);
        if ($file !== null) {
            if ($file->getMimeType() !== 'application/pdf') {
                throw ValidationException::withMessages(['file' => 'Upload the plan as a PDF, or paste its text.']);
            }
            $source = self::pdfText((string) $file->getRealPath());
        }
        if (mb_strlen($source) < 80) {
            throw ValidationException::withMessages(['file' => $file !== null ? 'We couldn\'t read text in that PDF (it may be a scan). Paste the plan\'s words instead.' : 'Paste the plan\'s words (at least a paragraph).']);
        }
        try {
            $result = app(AiGateway::class)->run(new AiTask(self::FEATURE, 'compensation.plan_reader', 'plan-read:'.$by->id.':'.CarbonImmutable::now()->format('YmdHisv'),
                inputs: [], untrustedContent: [mb_substr($source, 0, self::MAX_CHARS)], outputSchema: self::schema(), userId: $by->id));
        } catch (InsufficientCredits $e) {
            throw ValidationException::withMessages(['file' => $e->getMessage()]);
        } catch (ValidationException $e) {
            throw $e;
        } catch (Throwable $e) {
            report($e);
            throw ValidationException::withMessages(['file' => 'The plan reader didn\'t manage this one. Please try again, or set the plan up by hand.']);
        }
        $j = (array) $result->json;
        [$rules, $unclear] = self::rules($j);
        $name = mb_substr(trim((string) ($j['name'] ?? '')) ?: 'Plan from document', 0, 70).' (draft from document)';
        try {
            $plan = $this->plans->create(PlanType::Commission, $name, $rules, $by->id, mb_substr(trim((string) ($j['summary'] ?? '')), 0, 300) ?: null);
        } catch (ValidationException $e) {
            throw ValidationException::withMessages(['file' => 'The document\'s numbers don\'t make a complete plan: '.collect($e->errors())->flatten()->first().' Set it up by hand, or paste a clearer copy.']);
        }
        $evidence = array_values(array_filter(array_map(fn ($e): ?array => is_array($e) && is_string($e['rule'] ?? null) && is_string($e['quote'] ?? null)
            ? ['rule' => mb_substr($e['rule'], 0, 80), 'quote' => mb_substr($e['quote'], 0, 300)] : null, (array) ($j['evidence'] ?? []))));
        $unclear = array_values(array_unique(array_merge($unclear, array_map(fn ($u): string => mb_substr((string) $u, 0, 200), array_filter((array) ($j['unclear'] ?? []), 'is_string')))));

        return ['plan' => $plan, 'summary' => (string) ($j['summary'] ?? ''), 'evidence' => $evidence, 'unclear' => $unclear];
    }

    /**
     * The AI's reading as plan rules (dollars to cents, names to codes), plus what couldn't be used.
     *
     * @param  array<string, mixed>  $j
     * @return array{0: array<string, mixed>, 1: list<string>}
     */
    public static function rules(array $j): array
    {
        $unclear = [];
        $cents = fn ($v): ?int => is_numeric($v) && (float) $v >= 0 ? (int) round((float) $v * 100) : null;
        $pct = fn ($v): ?string => is_numeric($v) && (float) $v >= 0 && (float) $v <= 100 ? rtrim(rtrim(number_format((float) $v, 4, '.', ''), '0'), '.') : null;
        $rules = [];
        if (($agent = $pct($j['agent_percent'] ?? null)) !== null) {
            $rules['split'] = ['mode' => 'percentage', 'agent_percent' => $agent];
        } elseif (($fee = $cents($j['flat_fee_dollars'] ?? null)) !== null) {
            $rules['split'] = ['mode' => 'flat_fee', 'bands' => [['up_to_price_cents' => null, 'fee_cents' => $fee]]];
        } else {
            $unclear[] = 'No split or flat fee was found; the draft starts at 100% to the agent. Set the split before publishing.';
            $rules['split'] = ['mode' => 'percentage', 'agent_percent' => '100'];
        }
        if (($cap = $cents($j['cap_dollars'] ?? null)) !== null && $cap > 0) {
            $fixed = ($j['cap_starts'] ?? '') === 'fixed' && is_string($j['cap_start_month_day'] ?? null) && preg_match('/^\d{2}-\d{2}$/', (string) $j['cap_start_month_day']) === 1;
            $rules['cap'] = ['amount_cents' => $cap, 'anchor' => $fixed ? 'fixed' : 'anniversary'] + ($fixed ? ['anchor_month_day' => (string) $j['cap_start_month_day']] : []);
            $post = $j['post_cap_fee'] ?? null;
            if (is_array($post) && in_array($post['kind'] ?? null, ['flat', 'percent'], true) && is_numeric($post['amount'] ?? null)) {
                $rules['post_cap'] = ['fee' => $post['kind'] === 'flat' ? ['calc' => 'flat', 'amount' => (int) $cents($post['amount'])] : ['calc' => 'percent', 'amount' => (string) $pct($post['amount'])]];
            }
        }
        foreach (['pre_split', 'post_split'] as $stage) {
            $list = [];
            foreach ((array) ($j[$stage] ?? []) as $i => $d) {
                if (! is_array($d) || ! in_array($d['kind'] ?? null, ['flat', 'percent'], true) || ! is_numeric($d['amount'] ?? null) || trim((string) ($d['name'] ?? '')) === '') {
                    $unclear[] = 'A '.($stage === 'pre_split' ? 'before-split' : 'after-split').' fee could not be read.';

                    continue;
                }
                $code = trim(substr((string) preg_replace('/[^a-z0-9]+/', '_', strtolower((string) $d['name'])), 0, 30), '_') ?: 'fee_'.$i;
                $list[] = array_filter(['code' => $code, 'name' => mb_substr((string) $d['name'], 0, 60), 'calc' => $d['kind'],
                    'amount' => $d['kind'] === 'flat' ? $cents($d['amount']) : $pct($d['amount']),
                    'payable_to' => in_array($d['payable_to'] ?? null, ['brokerage', 'franchisor', 'vendor'], true) ? $d['payable_to'] : 'brokerage',
                    'annual_cap_cents' => isset($d['annual_cap_dollars']) && is_numeric($d['annual_cap_dollars']) ? $cents($d['annual_cap_dollars']) : null], fn ($v): bool => $v !== null);
            }
            if ($list !== []) {
                $rules[$stage] = $list;
            }
        }
        foreach ((array) ($j['deal_classes'] ?? []) as $c) {
            if (is_array($c) && DealClass::tryFrom((string) ($c['class'] ?? '')) !== null && ($p = $pct($c['agent_percent'] ?? null)) !== null) {
                $rules['deal_classes'][(string) $c['class']] = ['split' => ['mode' => 'percentage', 'agent_percent' => $p]];
            }
        }

        return [$rules, $unclear];
    }

    /**
     * @return array<string, mixed>
     */
    private static function schema(): array
    {
        $fee = ['type' => 'object', 'properties' => ['name' => ['type' => 'string'], 'kind' => ['type' => 'string'], 'amount' => ['type' => 'number'],
            'payable_to' => ['type' => 'string'], 'annual_cap_dollars' => ['type' => ['number', 'null']]]];

        return ['type' => 'object', 'required' => ['name', 'summary', 'evidence', 'unclear'], 'properties' => [
            'name' => ['type' => 'string'], 'summary' => ['type' => 'string'], 'agent_percent' => ['type' => ['string', 'null']], 'flat_fee_dollars' => ['type' => ['number', 'null']],
            'cap_dollars' => ['type' => ['number', 'null']], 'cap_starts' => ['type' => ['string', 'null']], 'cap_start_month_day' => ['type' => ['string', 'null']],
            'post_cap_fee' => ['type' => ['object', 'null']], 'pre_split' => ['type' => 'array', 'items' => $fee], 'post_split' => ['type' => 'array', 'items' => $fee],
            'deal_classes' => ['type' => 'array', 'items' => ['type' => 'object']],
            'evidence' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => ['rule' => ['type' => 'string'], 'quote' => ['type' => 'string']]]],
            'unclear' => ['type' => 'array', 'items' => ['type' => 'string']]]];
    }

    private static function pdfText(string $file): string
    {
        $p = new Process(['pdftotext', '-layout', '-l', '30', $file, '-']);
        $p->setTimeout(30);
        $p->run();

        return $p->isSuccessful() ? trim($p->getOutput()) : '';
    }
}
