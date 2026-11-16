<?php

declare(strict_types=1);

namespace Modules\Sites\Reviews;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Modules\Connect\Models\Connection;
use Modules\Core\Audit\AuditLogger;
use Modules\Core\Connect\ConnectorKit;
use Modules\Core\Identity\Enums\UserKind;
use Modules\Crm\FairHousing;
use Modules\Sites\SiteAccess;

/**
 * Reviews from other sites (specs/connect/20, C8a): kept as written, with their source and date.
 *
 * - Sources: Google Business Profile (read daily, and replies posted back to Google), RealSatisfied (the agent's
 *   public feed, daily); Zillow and Testimonial Tree once their partner access is approved.
 * - Each source's average is its own; nothing is blended without saying so.
 * - Reviews are never changed. The owner may hide one only for abuse, personal data or a proven falsehood; the
 *   reason and who hid it are kept (FTC rule on consumer reviews: no suppressing honest negative reviews).
 */
final class Reviews
{
    public const SOURCES = ['google' => 'Google', 'realsatisfied' => 'RealSatisfied', 'zillow' => 'Zillow', 'testimonial_tree' => 'Testimonial Tree'];

    public const HIDE_REASONS = ['abuse' => 'Abusive or hateful', 'personal_data' => 'Shares someone\'s personal details', 'false' => 'Proven false (not about us, or fake)'];

    /**
     * Saves reviews from a source (new ones added, changed ones updated; hidden ones stay hidden).
     *
     * @param  list<array{external_id: string, author: string|null, rating: int|null, text: string|null, url: string|null, reviewed_at: CarbonImmutable, reply?: string|null}>  $rows
     */
    public function save(string $source, ?int $userId, ?int $connectionId, array $rows): int
    {
        $n = 0;
        foreach ($rows as $r) {
            $rating = $r['rating'] !== null ? max(1, min(5, $r['rating'])) : null;
            Review::query()->updateOrCreate(['source' => $source, 'external_id' => mb_substr($r['external_id'], 0, 190)], [
                'user_id' => $userId, 'connection_id' => $connectionId, 'author' => $r['author'] !== null ? mb_substr($r['author'], 0, 120) : null, 'rating' => $rating,
                'text' => $r['text'] !== null ? mb_substr(strip_tags($r['text']), 0, 5000) : null, 'url' => $r['url'] !== null && str_starts_with($r['url'], 'https://') ? mb_substr($r['url'], 0, 500) : null,
                'reviewed_at' => $r['reviewed_at'],
            ] + (isset($r['reply']) ? ['reply' => mb_substr($r['reply'], 0, 4000)] : []));
            $n++;
        }

        return $n;
    }

    /**
     * One person's reviews (or the brokerage's, null) as shown publicly: per source, its count and average.
     *
     * @return array{count: int, sources: list<array{source: string, label: string, count: int, average: float|null}>, reviews: Collection<int, Review>}
     */
    public function shown(?int $userId, int $limit = 12): array
    {
        $all = Review::query()->where('hidden', false)->where('user_id', $userId)->orderByDesc('reviewed_at')->get();
        $sources = [];
        foreach ($all->groupBy('source') as $source => $rows) {
            $rated = $rows->whereNotNull('rating');
            $sources[] = ['source' => (string) $source, 'label' => self::SOURCES[$source] ?? (string) $source, 'count' => $rows->count(),
                'average' => $rated->isNotEmpty() ? round((float) $rated->avg('rating'), 1) : null];
        }

        return ['count' => $all->count(), 'sources' => $sources, 'reviews' => $all->take($limit)->values()];
    }

    /** A public reply (Google only takes replies; one per review, which can be changed). */
    public function reply(Review $review, User $by, string $text): void
    {
        self::guard($review, $by);
        $text = trim($text);
        if ($text === '' || mb_strlen($text) > 4000) {
            throw ValidationException::withMessages(['reply' => 'Write a reply of up to 4,000 characters.']);
        }
        if (($flags = FairHousing::screen($text, 'ads', 'blocked', 'Review reply')) !== []) {
            throw ValidationException::withMessages(['reply' => 'Fair Housing: please remove "'.implode('", "', $flags).'".']);
        }
        if ($review->source !== 'google') {
            throw ValidationException::withMessages(['reply' => self::SOURCES[$review->source].' replies are made on '.self::SOURCES[$review->source].' itself.']);
        }
        $c = $review->connection_id !== null ? Connection::query()->find($review->connection_id) : null;
        $token = $c !== null ? app(ConnectorKit::class)->accessToken($c->data()) : throw ValidationException::withMessages(['reply' => 'Connect Google Business Profile again.']);
        $r = Http::withToken($token)->acceptJson()->timeout(20)->put('https://mybusiness.googleapis.com/v4/'.$review->external_id.'/reply', ['comment' => $text]);
        if (! $r->successful()) {
            throw ValidationException::withMessages(['reply' => 'Google didn\'t accept the reply: '.mb_substr((string) ($r->json('error.message') ?? 'error '.$r->status()), 0, 200)]);
        }
        $review->forceFill(['reply' => $text, 'replied_at' => CarbonImmutable::now()])->save();
        app(AuditLogger::class)->record('reviews.reply', $review);
    }

    /** Hides a review from the websites, only for one of the allowed reasons, which are kept. */
    public function hide(Review $review, User $by, string $reason): void
    {
        abort_unless($by->kind === UserKind::Owner, 403);
        if (! isset(self::HIDE_REASONS[$reason])) {
            throw ValidationException::withMessages(['reason' => 'Reviews are hidden only when they are abusive, share personal details, or are proven false. Low ratings stay.']);
        }
        $review->forceFill(['hidden' => true, 'hidden_reason' => $reason, 'hidden_by' => $by->id])->save();
        app(AuditLogger::class)->record('reviews.hidden', $review, metadata: ['reason' => $reason], severity: 'notice');
    }

    public function unhide(Review $review, User $by): void
    {
        abort_unless($by->kind === UserKind::Owner, 403);
        $review->forceFill(['hidden' => false, 'hidden_reason' => null, 'hidden_by' => null])->save();
        app(AuditLogger::class)->record('reviews.shown', $review);
    }

    private static function guard(Review $review, User $by): void
    {
        abort_unless($review->user_id === $by->id || ($review->user_id === null && SiteAccess::manager($by)), 404);
    }
}
