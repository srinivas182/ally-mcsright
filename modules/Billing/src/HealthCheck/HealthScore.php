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
    public static function score(array $answers, array $tools, int $agents, ?array $website): array
    {
        $monthly = array_sum(array_column($tools, 'monthly_cents'));
        $perAgent = intdiv($monthly, max(1, $agents));
        $count = count($tools);
        $toolScore = max(0, match (true) {
            $perAgent <= 5_000 => 100, $perAgent <= 10_000 => 80, $perAgent <= 15_000 => 60, $perAgent <= 20_000 => 40, default => 20,
        } - 5 * max(0, $count - 5));

        $areas = [
            self::area('tools', 'Tools and cost', $toolScore,
                $count === 0 ? 'You did not list any tools.' : sprintf('%d tool%s at about %s per agent a month.', $count, $count === 1 ? '' : 's', self::usd($perAgent)),
                'One sign-in for CRM, websites, deals, e-signature and commissions: fewer bills and less for agents to learn.'),
            self::question($answers, 'lead_reply', 'Speed to new leads', 'Leads that wait go to the next agent who answers.',
                'RightAlly texts every new lead within a minute, day and night, and hands it to the right agent.'),
            self::question($answers, 'file_check', 'Deal file compliance', 'Problems found after closing are the costly ones.',
                'Deal-IQ checks every document as it is uploaded: missing signatures, missing addenda, mismatched amounts.'),
            self::question($answers, 'payout_days', 'Paying agents', 'Slow payouts are one of the top reasons agents leave.',
                'RightAlly works out each split and pays agents by bank transfer, the same or next day.'),
            self::question($answers, 'past_clients', 'Past clients and repeat business', 'Most repeat and referral business comes from past clients.',
                'Every agent gets "Who to call today", with past-client reminders and anniversary tasks set up for them.'),
        ];
        $areas[] = $website === null
            ? ['key' => 'website', 'title' => 'Website', 'score' => null, 'status' => 'not_checked', 'finding' => 'Not checked: no website address was given, or the check could not reach it.', 'fix' => 'RightAlly websites come ready for Google and for AI answers, in English and Spanish.']
            : self::area('website', 'Website', $website['score'], sprintf('Your home page passed checks worth %d%%.', $website['score']), 'RightAlly websites come ready for Google and for AI answers, in English and Spanish.');

        // Five areas always have a score; the website counts only once it has been checked.
        $scored = array_filter(array_column($areas, 'score'), fn ($s): bool => $s !== null);

        return ['overall' => (int) round(array_sum($scored) / count($scored)), 'areas' => $areas];
    }

    public static function usd(int $cents): string
    {
        return '$'.number_format($cents / 100, $cents % 100 === 0 ? 0 : 2);
    }

    /**
     * @param  array<string, string>  $answers
     * @return array{key: string, title: string, score: int|null, status: string, finding: string, fix: string}
     */
    private static function question(array $answers, string $key, string $title, string $why, string $fix): array
    {
        $answer = self::QUESTIONS[$key]['answers'][$answers[$key] ?? ''] ?? null;

        return self::area($key, $title, $answer[1] ?? 0, ($answer !== null ? 'You said: '.$answer[0].'. ' : '').$why, $fix);
    }

    /** @return array{key: string, title: string, score: int, status: string, finding: string, fix: string} */
    private static function area(string $key, string $title, int $score, string $finding, string $fix): array
    {
        return ['key' => $key, 'title' => $title, 'score' => $score, 'status' => $score >= 80 ? 'good' : ($score >= 50 ? 'fix' : 'urgent'), 'finding' => $finding, 'fix' => $fix];
    }
}
