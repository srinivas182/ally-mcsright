<?php

declare(strict_types=1);

namespace Modules\Movewise\Growth;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use Modules\Core\Audit\AuditLogger;
use Modules\Core\Tenancy\Models\Tenant;
use Modules\Core\Tenancy\TenantContext;
use Modules\Crm\FairHousing;
use Modules\Movewise\Models\Agent;
use Modules\Movewise\Models\Connection;
use Modules\Movewise\Models\Person;
use Modules\Sites\Reviews\Reviews;

/**
 * Agent reviews on Movewise (specs/movewise/00 §8, 07), following the FTC rule on consumer reviews:
 *
 * - Verified Movewise reviews come only from people introduced to that agent through Movewise. Every rating counts
 *   in the Movewise average; the words are published only when the person ticks "show my review", as their first name. Nobody pays for them and none are hidden for being bad.
 * - The agent can reply publicly once.
 * - Movewise removes a review only for abuse, personal data or a proven falsehood, and logs who and why.
 * - Imported reviews (Google, RealSatisfied, from the agent's own account, Connect C8) are shown separately with their
 *   source and date and never mixed into the Movewise average.
 */
final class AgentReviews
{
    public const REMOVAL_REASONS = ['abuse' => 'Abusive or hateful', 'personal_data' => 'Contains personal information', 'false' => 'Proven false'];

    /** @return array{average: float|null, count: int} the Movewise average from every verified rating */
    public function summary(Agent $agent): array
    {
        $rated = Connection::query()->where('agent_id', $agent->id)->whereNotNull('rating')->whereNull('review_removed_reason');

        return ['average' => (clone $rated)->count() > 0 ? round((float) (clone $rated)->avg('rating'), 1) : null, 'count' => (clone $rated)->count()];
    }

    /** @return list<array{ulid: string, author: string, rating: int, text: string|null, date: string, reply: string|null}> */
    public function published(Agent $agent, int $limit = 20): array
    {
        $out = [];
        foreach (Connection::query()->where('agent_id', $agent->id)->where('review_public', true)->whereNotNull('rating')->whereNull('review_removed_reason')
            ->orderByDesc('reviewed_at')->limit($limit)->get() as $c) {
            $p = Person::query()->find($c->person_id);
            $out[] = ['ulid' => $c->ulid, 'author' => $p !== null && $p->first_name !== null ? $p->first_name : 'A Movewise client', 'rating' => (int) $c->rating,
                'text' => $c->rating_note, 'date' => $c->reviewed_at?->toDateString() ?? '', 'reply' => $c->review_reply];
        }

        return $out;
    }

    /** @return list<array{source: string, author: string|null, rating: int|null, text: string|null, date: string}> from the agent's own account, labelled */
    public function imported(Agent $agent, int $limit = 6): array
    {
        $tenant = Tenant::query()->find($agent->tenant_id);
        if ($tenant === null) {
            return [];
        }
        try {
            return app(TenantContext::class)->runAs($tenant, function () use ($agent, $limit): array {
                $shown = app(Reviews::class)->shown($agent->user_id, $limit);
                $out = [];
                foreach ($shown['reviews'] as $r) {
                    $out[] = ['source' => Reviews::SOURCES[$r->source] ?? $r->source, 'author' => $r->author, 'rating' => $r->rating, 'text' => $r->text, 'date' => $r->reviewed_at->toDateString()];
                }

                return $out;
            });
        } catch (\Throwable $e) {
            report($e);

            return [];
        }
    }

    public function reply(Connection $c, User $by, string $text): void
    {
        $agent = Agent::query()->findOrFail($c->agent_id);
        abort_unless($agent->user_id === $by->id && app(TenantContext::class)->id() === $c->tenant_id, 403);
        $text = trim(mb_substr(strip_tags($text), 0, 1000));
        if ($c->rating === null || ! $c->review_public) {
            throw ValidationException::withMessages(['reply' => 'There is no public review to reply to.']);
        }
        if ($c->review_reply !== null) {
            throw ValidationException::withMessages(['reply' => 'You can reply to a review once.']);
        }
        if ($text === '' || FairHousing::screen($text, 'messages', 'blocked', 'Movewise review reply') !== []) {
            throw ValidationException::withMessages(['reply' => 'Write a reply that follows Fair Housing rules.']);
        }
        $c->forceFill(['review_reply' => $text, 'review_replied_at' => CarbonImmutable::now()])->save();
        app(AuditLogger::class)->record('movewise.review.replied', $c, tenantId: $c->tenant_id);
    }

    public function remove(Connection $c, User $hq, string $reason, string $note): void
    {
        if (! array_key_exists($reason, self::REMOVAL_REASONS) || trim($note) === '') {
            throw ValidationException::withMessages(['reason' => 'Reviews are removed only for abuse, personal data or a proven falsehood, with a note on why.']);
        }
        $c->forceFill(['review_removed_reason' => self::REMOVAL_REASONS[$reason].': '.mb_substr(trim($note), 0, 250), 'review_removed_by' => $hq->id])->save();
        app(AuditLogger::class)->record('movewise.review.removed', $c, metadata: ['reason' => $reason, 'note' => $note, 'by' => $hq->id], severity: 'warning', tenantId: $c->tenant_id);
    }
}
