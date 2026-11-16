<?php

declare(strict_types=1);

namespace Modules\Ads\Http;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Ads\Studio\BrandKits;
use Modules\Ads\Studio\StylePreviews;
use Modules\Ads\Studio\Styles;
use Modules\Core\Identity\Enums\UserKind;

/**
 * Ally Studio → Styles (specs/grow/13, SA1d): every style shown with the person's own listing and brand, the
 * finish, favourites for Autopilot to rotate through, and "style match" (tap the ones you love).
 */
final class StylesController
{
    public function index(Request $request, StylePreviews $previews, BrandKits $kits): Response
    {
        $user = self::user($request);
        $kit = $kits->forUser($user);
        $urls = $previews->urls($user);
        $match = array_values(array_unique(array_map(fn (string $k): string => Styles::key($k), array_filter(explode(',', (string) $request->query('match', '')), fn (string $k): bool => Styles::known($k)))));

        return Inertia::render('studio/styles', [
            'styles' => array_map(fn (string $k, array $s): array => ['key' => $k, 'name' => $s['name'], 'family' => $s['family'], 'about' => $s['about'],
                'dark' => $s['dark'], 'finish' => $s['finish'], 'preview' => $urls[$k] ?? null, 'allowed' => in_array($k, $kit['allowed'], true)], array_keys(Styles::ALL), Styles::ALL),
            'families' => Styles::FAMILIES,
            'finishes' => array_map(fn (array $f): string => $f['label'], Styles::FINISHES),
            'current' => ['style' => $kit['collection'], 'finish' => $kit['finish'], 'favorites' => $kit['favorites']],
            'locked' => $kit['locked'],
            'match' => $match,
        ]);
    }

    public function save(Request $request, BrandKits $kits): RedirectResponse
    {
        $data = $request->validate(['style' => ['required', 'string'], 'finish' => ['nullable', 'string'], 'favorites' => ['array', 'max:6'], 'favorites.*' => ['string']]);
        $kits->saveStyle(self::user($request), (string) $data['style'], $data['finish'] ?? null, array_values(array_map('strval', $data['favorites'] ?? [])));
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Your style is saved. New designs use it.']);

        return back();
    }

    /** Style match: from the styles someone loves, the two or three that suit them best. */
    public function match(Request $request): RedirectResponse
    {
        $liked = array_values(array_filter(array_map('strval', (array) $request->input('liked', [])), fn (string $k): bool => Styles::known($k)));
        abort_if($liked === [], 422);

        return redirect('/studio/styles?match='.implode(',', Styles::match($liked)));
    }

    private static function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User && in_array($user->kind, [UserKind::Owner, UserKind::Staff, UserKind::Agent], true), 403);

        return $user;
    }
}
