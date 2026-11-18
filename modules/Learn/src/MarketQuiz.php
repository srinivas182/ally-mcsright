<?php

declare(strict_types=1);

namespace Modules\Learn;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\TenantContext;
use Modules\Listings\Search\MarketStats;

/**
 * Local market mastery (LN4, E20): a short daily quiz on the agent's own area from today's real MLS numbers (homes for
 * sale, typical price, price per square foot, days on market, new listings). Each question has four choices; the
 * wrong ones are near enough to make the agent think. The same area gives the same quiz all day, so answers are
 * checked on the server and never sent to the page. Score and streak (days in a row) are kept.
 */
final class MarketQuiz
{
    public function __construct(private readonly TenantContext $tenants, private readonly MarketStats $stats) {}

    /**
     * Today's questions for an area (without the answers).
     *
     * @return array{place: string, name: string, questions: list<array{key: string, text: string, choices: list<string>}>}|null
     */
    public function today(string $place): ?array
    {
        $q = $this->questions($place);
        if ($q === null) {
            return null;
        }

        return ['place' => $place, 'name' => $q['name'], 'questions' => array_map(fn (array $x): array => ['key' => $x['key'], 'text' => $x['text'], 'choices' => $x['choices']], $q['list'])];
    }

    /**
     * Marks the answers and keeps the score.
     *
     * @param  array<string, string>  $answers  question key => chosen choice
     * @return array{score: int, total: int, right: array<string, string>, streak: int}
     */
    public function mark(User $user, string $place, array $answers): array
    {
        $q = $this->questions($place) ?? throw ValidationException::withMessages(['place' => 'Choose an area with enough homes for sale.']);
        $score = 0;
        $right = [];
        foreach ($q['list'] as $x) {
            $right[$x['key']] = $x['answer'];
            if (($answers[$x['key']] ?? null) === $x['answer']) {
                $score++;
            }
        }
        $today = CarbonImmutable::today()->toDateString();
        DB::connection(Connections::cell())->table('learn_market_quizzes')->updateOrInsert(['tenant_id' => $this->tenants->id(), 'user_id' => $user->id, 'place' => $place, 'on' => $today],
            ['score' => $score, 'total' => count($q['list']), 'updated_at' => now(), 'created_at' => now()]);

        return ['score' => $score, 'total' => count($q['list']), 'right' => $right, 'streak' => $this->streak($user)];
    }

    /** Days in a row (ending today or yesterday) with a quiz taken. */
    public function streak(User $user): int
    {
        $days = DB::connection(Connections::cell())->table('learn_market_quizzes')->where('tenant_id', $this->tenants->id())->where('user_id', $user->id)
            ->orderByDesc('on')->limit(400)->pluck('on')->map(fn ($d): string => substr((string) $d, 0, 10))->unique()->values()->all();
        $day = CarbonImmutable::today();
        if (! in_array($day->toDateString(), $days, true)) {
            $day = $day->subDay();
        }
        $n = 0;
        while (in_array($day->toDateString(), $days, true)) {
            $n++;
            $day = $day->subDay();
        }

        return $n;
    }

    /** @return array{name: string, list: list<array{key: string, text: string, choices: list<string>, answer: string}>}|null */
    private function questions(string $place): ?array
    {
        $s = $this->stats->forPlace($this->tenants->id(), $place);
        if ($s === null) {
            return null;
        }
        $seed = crc32($place.'|'.CarbonImmutable::today()->toDateString());
        $money = fn (int $v): string => '$'.number_format($v);
        $list = [];
        $add = function (string $key, string $text, ?int $value, callable $fmt, int $step) use (&$list, $seed): void {
            if ($value === null || $value <= 0) {
                return;
            }
            // Three wrong answers: 15–40% away, rounded like the right one, all different.
            $choices = [$fmt($value)];
            foreach ([0.75, 0.85, 1.2, 1.4] as $i => $f) {
                $v = (int) (round($value * $f / $step) * $step);
                if ($v > 0 && ! in_array($fmt($v), $choices, true) && count($choices) < 4) {
                    $choices[] = $fmt($v);
                }
            }
            $answer = $choices[0];
            mt_srand($seed + count($list));
            shuffle($choices);
            mt_srand();
            $list[] = ['key' => $key, 'text' => $text, 'choices' => $choices, 'answer' => $answer];
        };
        $area = (string) $s['name'];
        $add('median_price', "What is the typical asking price of a home for sale in {$area} today?", $s['median_price'] !== null ? (int) (round($s['median_price'] / 5000) * 5000) : null, $money, 5000);
        $add('active', "About how many homes are for sale in {$area} right now?", (int) $s['active'], fn (int $v): string => number_format($v), max(1, (int) round($s['active'] / 20)));
        $add('ppsf', "What is the typical asking price per square foot in {$area}?", $s['median_ppsf'] !== null ? (int) $s['median_ppsf'] : null, $money, 5);
        $add('dom', "How many days are homes in {$area} typically on the market?", $s['median_dom'] !== null ? (int) $s['median_dom'] : null, fn (int $v): string => $v.' days', 1);
        $add('new_30', "How many new listings came on the market in {$area} in the last 30 days?", (int) $s['new_30'], fn (int $v): string => number_format($v), max(1, (int) round($s['new_30'] / 20)));

        return count($list) >= 3 ? ['name' => $area, 'list' => $list] : null;
    }
}
