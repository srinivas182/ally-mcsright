<?php

declare(strict_types=1);

namespace Modules\Learn;

use Modules\Learn\Models\Course;
use Modules\Learn\Models\Enrolment;

/**
 * The skill scorecard (LN2, specs/learn/02): for each skill (prospecting, listings, buyers, contracts, compliance,
 * technology), how many of the brokerage's published courses on it the person has finished, and their best quiz
 * scores. It shows what to learn next; it is never used to rank people publicly.
 */
final class Scorecard
{
    public const SKILLS = ['prospecting' => 'Finding clients', 'listings' => 'Listings and sellers', 'buyers' => 'Buyers and showings', 'contracts' => 'Contracts and closing',
        'compliance' => 'Rules and compliance', 'technology' => 'Using RightAlly'];

    /**
     * @return list<array{skill: string, label: string, courses: int, finished: int, score: int|null, practice: int|null, next: array{ulid: string, title: string}|null}>
     */
    public static function forUser(int $userId): array
    {
        $courses = Course::query()->where('status', 'published')->whereNotNull('skill')->orderBy('title')->get()->groupBy('skill');
        $mine = Enrolment::query()->where('user_id', $userId)->get()->keyBy('course_id');
        $practice = RolePlay::practiceScores($userId);
        $out = [];
        foreach (self::SKILLS as $skill => $label) {
            $list = $courses->get($skill) ?? collect();
            $finished = $list->filter(fn (Course $c): bool => $mine->get($c->id)?->completed_at !== null);
            $scores = [];
            foreach ($list as $c) {
                foreach ((array) ($mine->get($c->id)->scores ?? []) as $s) {
                    $scores[] = (int) $s;
                }
            }
            $next = $list->first(fn (Course $c): bool => $mine->get($c->id)?->completed_at === null);
            $out[] = ['skill' => $skill, 'label' => $label, 'courses' => $list->count(), 'finished' => $finished->count(),
                'score' => $scores === [] ? null : (int) round(array_sum($scores) / count($scores)), 'practice' => $practice[$skill] ?? null, 'next' => $next !== null ? ['ulid' => $next->ulid, 'title' => $next->title] : null];
        }

        return $out;
    }
}
