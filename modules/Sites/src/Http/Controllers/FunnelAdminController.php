<?php

declare(strict_types=1);

namespace Modules\Sites\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Core\Identity\Enums\UserKind;
use Modules\Crm\Models\LeadSource;
use Modules\Sites\Funnels\Funnels;
use Modules\Sites\Models\Site;
use Modules\Sites\Models\SiteFunnel;
use Modules\Sites\SiteSetup;

/**
 * Grow → Funnels (specs/grow/03): make a buyer or seller funnel in three taps, see what each one brings in, pause it.
 * Owners and staff see and make the brokerage's funnels (and see everyone's); agents make and see their own.
 */
final class FunnelAdminController
{
    public function index(Request $request): Response
    {
        $user = self::user($request);
        $site = Site::query()->where('kind', 'brokerage')->first();
        $funnels = SiteFunnel::query()->when(! self::manager($user), fn ($q) => $q->where('owner_user_id', $user->id))->orderByDesc('id')->get();
        $sources = LeadSource::query()->whereIn('name', $funnels->map(fn (SiteFunnel $f): string => $f->sourceName())->all())->pluck('id', 'name');
        $host = $site !== null ? app(SiteSetup::class)->liveHost() : null;
        $base = is_string($host) && $host !== '' ? 'https://'.$host : null;

        return Inertia::render('grow/funnels', [
            'site_live' => $site?->status === 'live',
            'kinds' => array_map(fn (array $k): string => $k['label'], Funnels::KINDS),
            'manager' => self::manager($user),
            'funnels' => $funnels->map(function (SiteFunnel $f) use ($sources, $base): array {
                $source = $sources[$f->sourceName()] ?? null;

                return ['ulid' => $f->ulid, 'kind' => $f->kind, 'headline' => $f->headline, 'intro' => $f->intro, 'area' => $f->area, 'status' => $f->status,
                    'url' => $base !== null ? $base.'/go/'.$f->slug : null, 'visits' => $f->visits, 'leads' => $f->leads,
                    'conversion' => $f->visits > 0 ? round($f->leads * 100 / $f->visits, 1) : null, 'source' => $source,
                    'for' => $f->owner_user_id === null ? 'Brokerage' : (string) (User::query()->whereKey($f->owner_user_id)->value('first_name') ?? 'Agent')];
            })->all(),
        ]);
    }

    public function store(Request $request, Funnels $funnels): RedirectResponse
    {
        $user = self::user($request);
        $site = Site::query()->where('kind', 'brokerage')->first();
        abort_if($site === null, 409, 'Set up the brokerage website first.');
        $data = $request->validate(['kind' => ['required', 'string'], 'area' => ['required', 'string', 'max:80'], 'max_price' => ['nullable', 'string', 'max:20'],
            'min_price' => ['nullable', 'string', 'max:20'], 'beds' => ['nullable', 'integer', 'min:0', 'max:10'], 'headline' => ['nullable', 'string', 'max:120'],
            'intro' => ['nullable', 'string', 'max:400'], 'for_brokerage' => ['boolean']], ['area.required' => 'Name the area: a city, a neighbourhood or a ZIP code.']);
        $funnel = $funnels->create($site, $user, ['kind' => (string) $data['kind'], 'area' => (string) $data['area'], 'min_price' => self::dollars($data['min_price'] ?? null),
            'max_price' => self::dollars($data['max_price'] ?? null), 'beds' => isset($data['beds']) ? (int) $data['beds'] : null, 'headline' => $data['headline'] ?? null,
            'intro' => $data['intro'] ?? null, 'for_brokerage' => (bool) ($data['for_brokerage'] ?? true)]);
        Inertia::flash('toast', ['type' => 'success', 'message' => '"'.$funnel->headline.'" is live. Share its link or promote it with an ad.']);

        return back();
    }

    public function update(Request $request, string $funnel, Funnels $funnels): RedirectResponse
    {
        $user = self::user($request);
        $f = SiteFunnel::query()->where('ulid', $funnel)->firstOrFail();
        abort_unless(self::manager($user) || $f->owner_user_id === $user->id, 403);
        $data = $request->validate(['headline' => ['nullable', 'string', 'max:120'], 'intro' => ['nullable', 'string', 'max:400'], 'status' => ['nullable', 'in:live,paused']]);
        $funnels->update($f, $data, $user);
        Inertia::flash('toast', ['type' => 'success', 'message' => $f->status === 'paused' ? 'Paused. Its link now shows a friendly "no longer available" page.' : 'Saved.']);

        return back();
    }

    private static function dollars(mixed $value): ?int
    {
        $clean = preg_replace('/[^0-9.]/', '', is_scalar($value) ? (string) $value : '');

        return is_numeric($clean) ? (int) round((float) $clean) : null;
    }

    private static function manager(User $user): bool
    {
        return in_array($user->kind, [UserKind::Owner, UserKind::Staff], true);
    }

    private static function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User && in_array($user->kind, [UserKind::Owner, UserKind::Staff, UserKind::Agent], true), 403);

        return $user;
    }
}
