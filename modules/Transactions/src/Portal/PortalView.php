<?php

declare(strict_types=1);

namespace Modules\Transactions\Portal;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Core\Support\Connections;
use Modules\Transactions\Models\Deal;
use Modules\Transactions\Models\Document;

/**
 * What one person sees in the deal portal (specs/ai/02, §§6, 22). Buyers and sellers see the progress, every key
 * date, what is waiting on them and the documents the agent shared. The other side (lender, title, inspector …)
 * sees only the dates that concern them and what was asked of them: never the price talk, the people's details
 * or the documents.
 */
final class PortalView
{
    public const STAGES = ['contract', 'inspection', 'financing', 'closing', 'closed'];

    /** Which dates each of the other side sees, by words in the date's name. */
    private const ROLE_DATES = [
        'lender' => '/financ|apprais|loan|closing/i',
        'title' => '/earnest|title|survey|closing/i',
        'escrow' => '/earnest|escrow|closing/i',
        'attorney' => '/attorney|option|inspection|closing/i',
        'inspector' => '/inspection|option/i',
        'co_op_agent' => '/./',
        'other' => '/closing/i',
    ];

    /** Notes for the agent, not for anyone in the portal. */
    private const INTERNAL = '/^check the|internal|commission|broker/i';

    private const INSPECTION = '/option|inspection/i';

    private const FINANCING = '/financ|apprais|loan/i';

    /**
     * The milestones, in order, with where the deal is. Cash deals have no loan step.
     *
     * @return list<array{key: string, state: string}>
     */
    public static function stages(Deal $deal): array
    {
        $dates = self::rows($deal);
        $today = CarbonImmutable::today()->toDateString();
        $past = fn (string $pattern): ?bool => ($m = $dates->filter(fn ($d): bool => preg_match($pattern, (string) $d->label) === 1))->isEmpty()
            ? null : $m->every(fn ($d): bool => $d->done_at !== null || substr((string) $d->due_on, 0, 10) < $today);
        $closed = $deal->status === 'closed';
        $contract = $closed || $deal->status === 'under_contract';
        $closingSoon = $deal->closing_date !== null && $deal->closing_date->toDateString() <= CarbonImmutable::today()->addDays(10)->toDateString();
        $done = [
            'contract' => $contract,
            'inspection' => $closed || ($contract && ($past(self::INSPECTION) ?? ($closingSoon || ($deal->contract_date !== null && $deal->contract_date->toDateString() <= CarbonImmutable::today()->subDays(10)->toDateString())))),
            'financing' => $closed || ($contract && ($past(self::FINANCING) ?? $closingSoon)),
            'closing' => $closed,
            'closed' => $closed,
        ];
        $keys = array_values(array_filter(self::STAGES, fn (string $k): bool => ! ($k === 'financing' && $deal->financing === 'cash')));
        $current = null;
        $out = [];
        foreach ($keys as $k) {
            $state = $done[$k] && $current === null ? 'done' : ($current === null ? 'current' : 'next');
            if ($state === 'current') {
                $current = $k;
            }
            $out[] = ['key' => $k, 'state' => $state];
        }
        $last = array_pop($out); // never empty: "closed" is always a step
        $out[] = $closed ? ['key' => $last['key'], 'state' => 'current'] : $last;

        return $out;
    }

    /** The milestone the deal is at now (null before the contract is signed). */
    public static function stage(Deal $deal): ?string
    {
        if (! in_array($deal->status, ['under_contract', 'closed'], true)) {
            return null;
        }
        foreach (self::stages($deal) as $s) {
            if ($s['state'] === 'current') {
                return $s['key'];
            }
        }

        return null;
    }

    /**
     * The dates this person sees.
     *
     * @return list<array{label: string, date: string, when: string, done: bool, today: bool}>
     */
    public static function dates(Deal $deal, string $role, string $locale): array
    {
        $pattern = PortalAccess::isClient($role) ? '/./' : (self::ROLE_DATES[$role] ?? self::ROLE_DATES['other']);
        $today = CarbonImmutable::today()->toDateString();
        $rows = self::rows($deal)->filter(fn ($d): bool => preg_match(self::INTERNAL, (string) $d->label) !== 1 && preg_match($pattern, (string) $d->label) === 1)
            ->map(fn ($d): array => ['label' => Copy::label($locale, (string) $d->label), 'date' => substr((string) $d->due_on, 0, 10),
                'when' => Copy::date($locale, substr((string) $d->due_on, 0, 10)), 'done' => $d->done_at !== null, 'today' => substr((string) $d->due_on, 0, 10) === $today])->values()->all();
        if ($deal->closing_date !== null && preg_match($pattern, 'closing') === 1 && ! in_array('closing', array_map(fn (array $r): string => mb_strtolower($r['label']), $rows), true)) {
            $c = $deal->closing_date->toDateString();
            $rows[] = ['label' => Copy::label($locale, 'Closing'), 'date' => $c, 'when' => Copy::date($locale, $c), 'done' => $deal->status === 'closed', 'today' => $c === $today];
        }
        usort($rows, fn (array $a, array $b): int => strcmp($a['date'], $b['date']));

        return $rows;
    }

    /**
     * Upload links sent to this person that are still open.
     *
     * @return list<array{ulid: string, what: string}>
     */
    public static function requests(Deal $deal, \stdClass $access): array
    {
        return array_values(DB::connection(Connections::cell())->table('tx_upload_requests')->where('deal_id', $deal->id)->where('email', $access->email)
            ->whereNull('used_at')->where('expires_at', '>', now())->orderBy('id')->get(['ulid', 'what'])
            ->map(fn ($r): array => ['ulid' => (string) $r->ulid, 'what' => (string) $r->what])->all());
    }

    /**
     * Documents the agent shared with the clients (clients only).
     *
     * @return list<array{ulid: string, name: string, shared: string}>
     */
    public static function documents(Deal $deal, string $role): array
    {
        if (! PortalAccess::isClient($role)) {
            return [];
        }

        return array_values(Document::query()->where('deal_id', $deal->id)->whereNotNull('shared_at')->orderBy('id')->get()
            ->map(fn (Document $d): array => ['ulid' => $d->ulid, 'name' => $d->filename, 'shared' => CarbonImmutable::parse((string) $d->getAttribute('shared_at'))->toDateString()])->all());
    }

    /**
     * The next three things: what is waiting on them, then the dates coming up in the next two weeks.
     *
     * @return list<string>
     */
    public static function next(Deal $deal, \stdClass $access): array
    {
        $locale = (string) $access->locale;
        $items = array_map(fn (array $r): string => Copy::t($locale, 'upload_for', ['what' => $r['what']]), self::requests($deal, $access));
        $soon = CarbonImmutable::today()->addDays(14)->toDateString();
        foreach (self::dates($deal, (string) $access->role, $locale) as $d) {
            if (! $d['done'] && $d['date'] >= CarbonImmutable::today()->toDateString() && $d['date'] <= $soon) {
                $items[] = $d['label'].': '.$d['when'];
            }
        }

        return array_slice($items, 0, 3);
    }

    /**
     * The whole page for one person.
     *
     * @return array<string, mixed>
     */
    public static function page(Deal $deal, \stdClass $access): array
    {
        $locale = Copy::locale((string) $access->locale);
        $role = (string) $access->role;
        $client = PortalAccess::isClient($role);
        $agent = User::query()->whereKey($deal->agent_user_id)->first();
        $db = DB::connection(Connections::cell());

        return [
            'access' => ['ulid' => (string) $access->ulid, 'name' => (string) $access->name, 'role' => $role, 'locale' => $locale, 'phone' => $access->phone,
                'texts' => $access->sms_consent_at !== null, 'client' => $client],
            'deal' => ['street' => $deal->street, 'city' => $deal->city, 'state' => $deal->state_code, 'closed' => $deal->status === 'closed'],
            'stages' => $client ? array_map(fn (array $s): array => $s + ['label' => Copy::t($locale, 'stage.'.$s['key']), 'note' => Copy::t($locale, 'stage_note.'.$s['key'])], self::stages($deal)) : [],
            'next' => self::next($deal, $access),
            'dates' => self::dates($deal, $role, $locale),
            'requests' => self::requests($deal, $access),
            'documents' => self::documents($deal, $role),
            'agent' => ['name' => $agent->name ?? '', 'email' => $agent?->email, 'phone' => $agent?->getAttribute('phone')],
            'questions' => $client ? array_values($db->table('tx_portal_questions')->where('access_id', $access->id)->orderByDesc('id')->limit(10)->get()
                ->map(fn ($q): array => ['question' => (string) $q->question, 'answer' => $q->answer, 'status' => (string) $q->status, 'reply' => $q->agent_reply,
                    'sources' => array_values(array_map(fn ($s): string => Copy::t($locale, 'source.'.$s), (array) json_decode((string) ($q->sources ?? '[]'), true)))])->all()) : [],
            'checkin' => $client && $deal->status === 'closed' && ! $db->table('tx_portal_feedback')->where('access_id', $access->id)->exists(),
            't' => Copy::all($locale),
            'locales' => Copy::LOCALES,
        ];
    }

    /**
     * @return Collection<int, \stdClass>
     */
    private static function rows(Deal $deal): Collection
    {
        return DB::connection(Connections::cell())->table('tx_dates')->where('deal_id', $deal->id)->orderBy('due_on')->get(['label', 'due_on', 'done_at']);
    }
}
