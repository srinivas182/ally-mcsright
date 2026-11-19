<?php

declare(strict_types=1);

namespace Modules\Marketing\Http;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Core\Audit\AuditLogger;
use Modules\Core\Support\Connections;
use Modules\Marketing\Content\Cadence;
use Modules\Marketing\Content\IndexStats;
use Modules\Marketing\Content\Publisher;
use Modules\Marketing\Content\Topics;

/** HQ → Content desk (MK3): states, levels and the morning review queue. */
final class HqContentController
{
    public function index(): Response
    {
        $db = DB::connection(Connections::control());
        $desks = $db->table('content_desks')->where('tenant_id', 0)->orderBy('state')->get()->map(function (object $d) use ($db): array {
            $stats = $db->table('content_index_stats')->where('desk_id', $d->id)->orderByDesc('day')->first();

            return ['id' => (int) $d->id, 'state' => (string) $d->state, 'name' => Topics::STATES[(string) $d->state] ?? (string) $d->state, 'mode' => (string) $d->mode,
                'level' => (int) $d->level, 'allowance' => Cadence::allowance($d), 'today' => Cadence::remainingToday($d), 'paused' => $d->paused_reason,
                'cities' => implode(', ', (array) json_decode((string) ($d->cities ?? '[]'), true)),
                'indexed' => $stats !== null ? ['submitted' => (int) $stats->submitted, 'indexed' => (int) $stats->indexed, 'warnings' => (int) $stats->warnings, 'day' => (string) $stats->day, 'source' => (string) $stats->source] : null,
                'published' => $db->table('content_drafts')->where('desk_id', $d->id)->where('status', 'published')->count()];
        })->values();
        $drafts = $db->table('content_drafts')->where('tenant_id', 0)->whereIn('status', ['review', 'skipped'])->where('created_at', '>', now()->subDays(14))->orderBy('status')->orderByDesc('id')->limit(100)->get()
            ->map(fn (object $d): array => ['ulid' => (string) $d->ulid, 'state' => (string) $d->state, 'city' => (string) $d->city, 'kind' => (string) $d->kind, 'status' => (string) $d->status,
                'title' => (string) $d->title, 'description' => (string) $d->description, 'body' => (string) $d->body, 'note' => $d->note,
                'faq' => (array) json_decode((string) ($d->faq ?? '[]'), true), 'facts' => (array) json_decode((string) ($d->facts ?? '[]'), true),
                'similarity' => (float) $d->similarity, 'created_at' => (string) $d->created_at])->values();

        return Inertia::render('hq/content', ['desks' => $desks, 'drafts' => $drafts, 'states' => Topics::STATES,
            'published' => $db->table('content_drafts')->where('tenant_id', 0)->where('status', 'published')->orderByDesc('reviewed_at')->limit(30)->get(['title', 'published_url', 'reviewed_at', 'state'])]);
    }

    public function saveDesk(Request $request, AuditLogger $audit): RedirectResponse
    {
        $d = $request->validate(['state' => ['required', 'in:'.implode(',', array_keys(Topics::STATES))], 'mode' => ['required', 'in:off,test,live'], 'cities' => ['nullable', 'string', 'max:2000']]);
        $cities = array_values(array_filter(array_map(fn (string $c): string => mb_substr(trim($c), 0, 80), explode(',', (string) ($d['cities'] ?? '')))));
        $db = DB::connection(Connections::control())->table('content_desks');
        $key = ['tenant_id' => 0, 'user_id' => null, 'state' => $d['state']];
        if ($db->clone()->where('tenant_id', 0)->whereNull('user_id')->where('state', $d['state'])->exists()) {
            $db->clone()->where('tenant_id', 0)->whereNull('user_id')->where('state', $d['state'])->update(['mode' => $d['mode'], 'cities' => json_encode($cities), 'updated_at' => now()]);
        } else {
            $db->clone()->insert($key + ['mode' => $d['mode'], 'cities' => json_encode($cities), 'level' => 1, 'level_since' => now(), 'created_at' => now(), 'updated_at' => now()]);
        }
        $audit->record('marketing.content.desk', metadata: ['state' => $d['state'], 'mode' => $d['mode']], severity: 'notice');
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Saved. It starts at 3 a week; more unlocks as Google indexes the pages.']);

        return back();
    }

    public function approve(Request $request, string $draft, Publisher $publisher): RedirectResponse
    {
        $d = $request->validate(['title' => ['nullable', 'string', 'max:120'], 'description' => ['nullable', 'string', 'max:300'], 'body' => ['nullable', 'string', 'max:60000']]);
        $url = $publisher->approve($draft, self::user($request), array_filter($d, fn ($v): bool => is_string($v) && $v !== ''));
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Published: '.$url]);

        return back();
    }

    public function reject(Request $request, string $draft, Publisher $publisher): RedirectResponse
    {
        $d = $request->validate(['why' => ['nullable', 'string', 'max:300']]);
        $publisher->reject($draft, self::user($request), (string) ($d['why'] ?? ''));

        return back();
    }

    public function manualStats(Request $request, int $desk, IndexStats $stats): RedirectResponse
    {
        $d = $request->validate(['submitted' => ['required', 'integer', 'min:0'], 'indexed' => ['required', 'integer', 'min:0'], 'warnings' => ['required', 'integer', 'min:0']]);
        $row = DB::connection(Connections::control())->table('content_desks')->where('id', $desk)->where('tenant_id', 0)->first();
        abort_if($row === null, 404);
        $stats->saveManual($row, (int) $d['submitted'], (int) $d['indexed'], (int) $d['warnings']);

        return back();
    }

    private static function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
