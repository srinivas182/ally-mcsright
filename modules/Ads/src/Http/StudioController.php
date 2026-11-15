<?php

declare(strict_types=1);

namespace Modules\Ads\Http;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Ads\Social\SocialPosts;
use Modules\Ads\Studio\BrandKits;
use Modules\Ads\Studio\StudioPlans;
use Modules\Connect\AppRegistry;
use Modules\Core\Connect\AppCatalog;
use Modules\Core\Identity\Enums\UserKind;

/** Grow → Ally Studio (specs/grow/13): the package and this month's use, the brand kit, and where posts go. */
final class StudioController
{
    public function __construct(private readonly StudioPlans $plans, private readonly BrandKits $kits) {}

    public function index(Request $request): Response
    {
        $user = self::user($request);
        $registry = app(AppRegistry::class);
        $offers = [];
        foreach (array_keys(StudioPlans::PACKAGES) as $key) {
            $app = app(AppCatalog::class)->find($key);
            $price = $app !== null && $registry->ready($app) ? $registry->price($app) : null;
            if ($app !== null && $price !== null && ($key !== 'studio_brokerage' || $user->kind === UserKind::Owner)) {
                $p = $this->plans->packages()[$key] ?? null;
                $offers[] = ['key' => $key, 'name' => $app->name, 'summary' => $app->summary, 'cents' => $price['cents'], 'allowances' => $p,
                    'features' => array_map(fn (string $f): string => StudioPlans::FEATURES[$f] ?? $f, $p['features'] ?? [])];
            }
        }

        return Inertia::render('studio/index', [
            'plan' => $this->plans->current($user),
            'usage' => $this->plans->usage($user),
            'items' => StudioPlans::ITEMS,
            'offers' => $offers,
            'missing' => $this->kits->missing($user),
            'kit' => $this->kits->forUser($user),
            'collections' => BrandKits::COLLECTIONS,
            'social' => app(SocialPosts::class)->apps($user),
        ]);
    }

    public function brand(Request $request): Response
    {
        $user = self::user($request);

        return Inertia::render('studio/brand', [
            'kit' => $this->kits->forUser($user),
            'collections' => BrandKits::COLLECTIONS,
            'fonts' => BrandKits::FONTS,
            'languages' => BrandKits::LANGUAGES,
            'is_owner' => $user->kind === UserKind::Owner,
            'brokerage' => $user->kind === UserKind::Owner ? $this->kits->brokerage() : null,
            'cutout' => $this->kits->cutoutReady(),
        ]);
    }

    public function saveBrand(Request $request): RedirectResponse
    {
        $user = self::user($request);
        $request->validate(['headshot' => ['nullable', 'file', 'max:10240'], 'logo' => ['nullable', 'file', 'max:10240'], 'logo_dark' => ['nullable', 'file', 'max:10240']]);
        $brokerage = $request->input('for') === 'brokerage';
        $this->kits->save($user, $brokerage ? null : $user, $request->only(['collection', 'colors', 'fonts', 'details', 'languages', 'brand_lock']),
            ['headshot' => $request->file('headshot'), 'logo' => $request->file('logo'), 'logo_dark' => $request->file('logo_dark')]);
        Inertia::flash('toast', ['type' => 'success', 'message' => $brokerage ? 'Brokerage brand saved.' : 'Your brand kit is saved.']);

        return back();
    }

    private static function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User && in_array($user->kind, [UserKind::Owner, UserKind::Staff, UserKind::Agent], true), 403);

        return $user;
    }
}
