<?php

declare(strict_types=1);

namespace Modules\Marketing\Content;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Billing\Entitlements;
use Modules\Core\Audit\AuditLogger;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\TenantContext;
use Modules\Sites\Ai\ContentStudio;
use Modules\Sites\SiteAccess;
use Modules\Sites\SiteSetup;
use stdClass;

/**
 * MK4: the AI blog writer — the content desk sold as an add-on to brokerages and agents. It writes local posts for
 * home buyers and sellers on the brokerage's own website, under the brokerage's name or one of its agents'.
 *
 * Why it is never thin or duplicate content, even with ten brokerages in one city:
 * - every post must carry something only this brokerage has: the owner's own words and ideas, or its MLS figures for
 *   the area; with neither, the day is skipped and the owner told why;
 * - topics and angles are booked city by city across every desk (HQ and all brokerages) for six months, so no two
 *   sites in a city get the same post; ideas the owner gives are theirs alone;
 * - every draft is compared with everything written in the state (Similarity::MAX) and rewritten or skipped;
 * - the same Cadence as HQ: 3 a week to start, up to 1 a day once Google indexes the posts — never more;
 * - a person approves every post (the broker, who supervises the brokerage's advertising), and each names its author.
 */
final class TenantDesk
{
    public const FEATURE = 'content.blog';

    public function __construct(private readonly TenantContext $tenants, private readonly SiteSetup $sites) {}

    /** The current brokerage's desk (agentId: an agent's own desk), or null. */
    public function desk(?int $agentId = null): ?stdClass
    {
        return DB::connection(Connections::control())->table('content_desks')->where('tenant_id', (int) $this->tenants->id())
            ->when($agentId === null, fn ($q) => $q->whereNull('user_id'), fn ($q) => $q->where('user_id', $agentId))->first();
    }

    /** @return list<stdClass> every desk of the current brokerage */
    public function desks(): array
    {
        return array_values(DB::connection(Connections::control())->table('content_desks')->where('tenant_id', (int) $this->tenants->id())->orderBy('id')->get()->all());
    }

    /** Whether the brokerage's package or extras include the blog writer. */
    public static function included(User $user): bool
    {
        return app(Entitlements::class)->allows($user, self::FEATURE);
    }

    /**
     * Owners and staff set a desk up (for the brokerage, or for one agent); an agent may only change the ideas and words
     * on their own desk.
     *
     * @param  array{mode?: string, state?: string, cities?: string, ideas?: string, words?: string, agent?: int|null}  $in
     */
    public function save(User $by, array $in): stdClass
    {
        if (! self::included($by)) {
            throw ValidationException::withMessages(['desk' => 'The AI blog writer is not in your package. Add it under Billing → Extras.']);
        }
        $manager = SiteAccess::manager($by);
        $agentId = $manager ? (isset($in['agent']) && (int) $in['agent'] > 0 ? (int) $in['agent'] : null) : $by->id;
        if ($agentId !== null && ! User::query()->whereKey($agentId)->exists()) {
            throw ValidationException::withMessages(['agent' => 'Choose one of your agents.']);
        }
        $desk = $this->desk($agentId);
        if (! $manager && $desk === null) {
            throw ValidationException::withMessages(['desk' => 'Ask your broker to turn on the blog writer for you.']);
        }
        $ideas = self::lines(preg_split('/\r?\n/', (string) ($in['ideas'] ?? '')) ?: [], 200, 20);
        $words = mb_substr(trim(strip_tags((string) ($in['words'] ?? ''))), 0, 3000);
        $inputs = json_encode(['ideas' => $ideas, 'words' => $words]);
        $db = DB::connection(Connections::control())->table('content_desks');
        if (! $manager) {
            $db->where('id', (int) $desk->id)->update(['inputs' => $inputs, 'updated_at' => now()]);
        } else {
            $state = strtoupper((string) ($in['state'] ?? ''));
            $site = $this->sites->brokerageSite();
            $info = $site !== null ? (array) $site->info : [];
            $state = isset(Topics::STATES[$state]) ? $state : strtoupper((string) ($info['state'] ?? ''));
            if (! isset(Topics::STATES[$state])) {
                throw ValidationException::withMessages(['state' => 'Choose your state.']);
            }
            $cities = self::lines(explode(',', (string) ($in['cities'] ?? ($info['area'] ?? ''))), 80, 10);
            if ($cities === []) {
                throw ValidationException::withMessages(['cities' => 'Name the towns or cities you work in.']);
            }
            $mode = in_array($in['mode'] ?? 'off', ['off', 'test', 'live'], true) ? (string) ($in['mode'] ?? 'off') : 'off';
            $values = ['state' => $state, 'mode' => $mode, 'cities' => json_encode($cities), 'inputs' => $inputs, 'updated_at' => now()];
            if ($desk === null) {
                $db->insert(['tenant_id' => (int) $this->tenants->id(), 'user_id' => $agentId, 'level' => 1, 'level_since' => now(), 'created_at' => now()] + $values);
            } else {
                // Changing state keeps the level: it is earned by this website, not by the state.
                $db->where('id', $desk->id)->update($values);
            }
        }
        app(AuditLogger::class)->record('marketing.blog_writer.saved', metadata: ['agent' => $agentId, 'ideas' => count($ideas)], severity: 'info');

        return $this->desk($agentId) ?? throw new \LogicException('Desk not saved.');
    }

    /**
     * Writes today's drafts for one desk, in its brokerage's context. Returns how many reached the review queue.
     */
    public function writeFor(stdClass $desk, Writer $writer, ContentStudio $studio): int
    {
        $site = $this->sites->brokerageSite();
        if ($site === null) {
            return 0;
        }
        $info = (array) $site->info;
        $brokerage = (string) ($info['name'] ?? 'our brokerage');
        $agent = $desk->user_id !== null ? User::query()->find((int) $desk->user_id) : null;
        $inputs = (array) json_decode((string) ($desk->inputs ?? '{}'), true);
        $words = trim((string) ($inputs['words'] ?? ''));
        $written = 0;
        for ($i = Cadence::remainingToday($desk); $i > 0; $i--) {
            $topic = $this->ownIdea($desk, (array) ($inputs['ideas'] ?? [])) ?? Topics::next($desk, Topics::TENANT);
            if ($topic === null) {
                $this->skip($desk, (string) (((array) json_decode((string) $desk->cities, true))[0] ?? ''), 'Every topic for your area is taken for now. Add your own ideas so the writer has something only you can say.');
                break;
            }
            $facts = $studio->facts($site, $topic['city'])['lines'];
            if ($words === '' && $facts === []) {
                $this->skip($desk, $topic['city'], 'Nothing unique to say yet: add a few sentences in your own words, or connect your MLS so posts can use your area\'s figures.');
                break;
            }
            $extra = "Write as {$brokerage}".($agent !== null ? ", by {$agent->name}" : '').', a local real estate '.($agent !== null ? 'agent' : 'brokerage').'.'
                ."\nThis post must include something only {$brokerage} can say: use their own words and the market figures below, quoting them accurately."
                .($facts !== [] ? "\nMarket figures (source name: mls-figures):\n".implode("\n", $facts) : '')
                .($words !== '' ? "\nTheir own words (source name: your-words):\n".$words : '')
                ."\nEnd by inviting the reader to talk to ".($agent !== null ? $agent->name : $brokerage).' — never promise results.';
            $written += $writer->draft($desk, $topic, $brokerage, 'home buyers and sellers', $extra) ? 1 : 0;
        }

        return $written;
    }

    /**
     * The next of the owner's own ideas not yet written, booked so it is never used twice.
     *
     * @param  array<mixed>  $ideas
     * @return array{city: string, topic: string, angle: string, kind: string, title: string}|null
     */
    private function ownIdea(stdClass $desk, array $ideas): ?array
    {
        $db = DB::connection(Connections::control());
        $city = (string) (((array) json_decode((string) $desk->cities, true))[0] ?? (Topics::STATES[(string) $desk->state] ?? ''));
        foreach ($ideas as $idea) {
            $idea = trim((string) $idea);
            $key = 'idea-'.substr(hash('sha256', mb_strtolower($idea)), 0, 12);
            if ($idea === '' || $db->table('content_bookings')->where('desk_id', $desk->id)->where('topic', $key)->exists()) {
                continue;
            }
            $db->table('content_bookings')->insert(['state' => (string) $desk->state, 'city' => $city, 'topic' => $key, 'angle' => 'own', 'desk_id' => $desk->id,
                'until' => now()->addYears(10), 'created_at' => now(), 'updated_at' => now()]);

            return ['city' => $city, 'topic' => $key, 'angle' => 'own-idea', 'kind' => 'post', 'title' => mb_substr($idea, 0, 120)];
        }

        return null;
    }

    /**
     * @param  array<string>  $raw
     * @return list<string> trimmed, plain, non-empty lines, each at most $max characters, at most $count of them
     */
    private static function lines(array $raw, int $max, int $count): array
    {
        $out = [];
        foreach ($raw as $line) {
            $line = mb_substr(trim(strip_tags($line)), 0, $max);
            if ($line !== '' && count($out) < $count) {
                $out[] = $line;
            }
        }

        return $out;
    }

    private function skip(stdClass $desk, string $city, string $why): void
    {
        DB::connection(Connections::control())->table('content_drafts')->insert(['ulid' => (string) Str::ulid(), 'desk_id' => $desk->id, 'tenant_id' => (int) $desk->tenant_id,
            'state' => (string) $desk->state, 'city' => mb_substr($city, 0, 80), 'kind' => 'post', 'topic' => 'none', 'title' => 'Skipped today', 'slug' => '', 'description' => '',
            'body' => '', 'status' => 'skipped', 'note' => mb_substr($why, 0, 300), 'created_at' => now(), 'updated_at' => now()]);
    }
}
