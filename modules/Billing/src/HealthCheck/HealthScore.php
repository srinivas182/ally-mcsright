<?php

declare(strict_types=1);

namespace Modules\Billing\HealthCheck;

/**
 * Turns the broker's answers into scores with a plain-English finding and fix for each area (specs/billing/06). The
 * rules are fixed and shown in the spec: no AI decides a score, so the same answers always give the same report.
 */
final class HealthScore
{
    /** The questions, their answers and each answer's score. */
    public const QUESTIONS = [
        'lead_reply' => ['label' => 'How fast does a new online lead usually hear from your brokerage?', 'answers' => [
            'minutes' => ['Within 5 minutes', 100], 'hour' => ['Within an hour', 70], 'day' => ['The same day', 40], 'later' => ['Later than that', 15], 'unknown' => ['I don\'t know', 10],
        ]],
        'file_check' => ['label' => 'When are deal files checked for missing signatures and documents?', 'answers' => [
            'as_uploaded' => ['As each document comes in', 100], 'before_closing' => ['Before closing', 70], 'after_closing' => ['After closing', 35], 'rarely' => ['Rarely, or when there is a problem', 10],
        ]],
        'payout_days' => ['label' => 'After closing, how long until agents are paid?', 'answers' => [
            'same_day' => ['The same day', 100], 'few_days' => ['Within 2–3 days', 75], 'week' => ['About a week', 45], 'longer' => ['Longer', 20],
        ]],
        'past_clients' => ['label' => 'Do your agents keep in touch with past clients from one shared system?', 'answers' => [
            'yes' => ['Yes, all of them', 100], 'some' => ['Some agents do', 55], 'no' => ['No, each agent does their own thing', 20],
        ]],
    ];

    public const WHO_PAYS = ['brokerage' => 'The brokerage pays for agents\' software', 'agents' => 'Agents pay for their own software'];

    /**
     * @param  array<string, string>  $answers
     * @param  list<array{key: string, name: string, monthly_cents: int}>  $tools  monthly cost already multiplied out
     * @param  array{score: int}|null  $website
     * @return array{overall: int, areas: list<array{key: string, title: string, score: int|null, status: string, finding: string, fix: string}>}
     */
    public static function score(array $answers, array $tools, int $agents, ?array $website, string $lang = 'en'): array
    {
        $t = fn (string $key, array $with = []): string => HealthCopy::t($lang, $key, $with);
        $monthly = array_sum(array_column($tools, 'monthly_cents'));
        $perAgent = intdiv($monthly, max(1, $agents));
        $count = count($tools);
        $toolScore = max(0, match (true) {
            $perAgent <= 5_000 => 100, $perAgent <= 10_000 => 80, $perAgent <= 15_000 => 60, $perAgent <= 20_000 => 40, default => 20,
        } - 5 * max(0, $count - 5));

        $areas = [self::area('tools', $t('area.tools'), $toolScore, $count === 0 ? $t('area.tools.none') : $t('area.tools.found', ['count' => $count, 'cost' => self::usd($perAgent)]), $t('area.tools.fix'))];
        foreach (['lead_reply', 'file_check', 'payout_days', 'past_clients'] as $key) {
            $answer = $answers[$key] ?? '';
            $score = self::QUESTIONS[$key]['answers'][$answer][1] ?? 0;
            $said = isset(self::QUESTIONS[$key]['answers'][$answer]) ? $t('you_said', ['answer' => $t("a.{$key}.{$answer}")]).' ' : '';
            $areas[] = self::area($key, $t("area.{$key}"), $score, $said.$t("area.{$key}.why"), $t("area.{$key}.fix"));
        }
        $areas[] = $website === null
            ? ['key' => 'website', 'title' => $t('area.website'), 'score' => null, 'status' => 'not_checked', 'finding' => $t('area.website.none'), 'fix' => $t('area.website.fix')]
            : self::area('website', $t('area.website'), $website['score'], $t('area.website.found', ['score' => $website['score']]), $t('area.website.fix'));

        // Five areas always have a score; the website counts only once it has been checked.
        $scored = array_filter(array_column($areas, 'score'), fn ($s): bool => $s !== null);

        return ['overall' => (int) round(array_sum($scored) / count($scored)), 'areas' => $areas];
    }

    public static function usd(int $cents): string
    {
        return '$'.number_format($cents / 100, $cents % 100 === 0 ? 0 : 2);
    }

    /** @return array{key: string, title: string, score: int, status: string, finding: string, fix: string} */
    private static function area(string $key, string $title, int $score, string $finding, string $fix): array
    {
        return ['key' => $key, 'title' => $title, 'score' => $score, 'status' => $score >= 80 ? 'good' : ($score >= 50 ? 'fix' : 'urgent'), 'finding' => $finding, 'fix' => $fix];
    }
}
