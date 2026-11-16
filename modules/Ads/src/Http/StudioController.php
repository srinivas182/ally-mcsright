<?php

declare(strict_types=1);

namespace Modules\Ads\Http;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Ads\ListingAds;
use Modules\Ads\Social\SocialPosts;
use Modules\Ads\Studio\BrandKits;
use Modules\Ads\Studio\Canva\CanvaStudio;
use Modules\Ads\Studio\Design;
use Modules\Ads\Studio\Designer;
use Modules\Ads\Studio\StudioPlans;
use Modules\Ads\Studio\Styles;
use Modules\Connect\AppRegistry;
use Modules\Core\Connect\AppCatalog;
use Modules\Core\Identity\Enums\UserKind;
use Symfony\Component\HttpFoundation\StreamedResponse;
use ZipArchive;

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
            'collections' => BrandKits::collections(),
            'social' => app(SocialPosts::class)->apps($user),
        ]);
    }

    public function brand(Request $request): Response
    {
        $user = self::user($request);

        return Inertia::render('studio/brand', [
            'kit' => $this->kits->forUser($user),
            'collections' => BrandKits::collections(),
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
        $this->kits->save($user, $brokerage ? null : $user, $request->only(['collection', 'finish', 'colors', 'fonts', 'details', 'languages', 'brand_lock', 'styles']),
            ['headshot' => $request->file('headshot'), 'logo' => $request->file('logo'), 'logo_dark' => $request->file('logo_dark')]);
        Inertia::flash('toast', ['type' => 'success', 'message' => $brokerage ? 'Brokerage brand saved.' : 'Your brand kit is saved.']);

        return back();
    }

    /** Studio → Create: choose a moment (and a listing), get three designs, download or post one. */
    public function create(Request $request, Designer $designer, ListingAds $ads): Response
    {
        $user = self::user($request);
        $batch = $request->query('batch');
        $row = fn (Design $d): array => ['ulid' => $d->ulid, 'status' => $d->status, 'collection' => (string) Styles::get($d->collection)['name'], 'variant' => $d->variant,
            'moment' => Designer::MOMENTS[$d->moment][0] ?? $d->moment, 'checks' => $d->checks ?? [], 'used' => $d->used_at !== null,
            'images' => array_map(fn (string $size): ?string => $designer->url($d, $size), array_combine(array_keys(Designer::SIZES), array_keys(Designer::SIZES))),
            'carousel' => $designer->carousel($d), 'reelable' => (Designer::MOMENTS[$d->moment][1] ?? '') === 'listing' && $d->variant !== 'canva', 'listing' => $d->listing_ulid,
            'canva' => $d->variant === 'canva'];

        return Inertia::render('studio/create', [
            'ready' => $this->plans->current($user) !== null,
            'moments' => $designer->moments($user),
            'styles' => array_map(fn (string $k): array => ['key' => $k, 'name' => (string) Styles::get($k)['name']], $this->kits->forUser($user)['allowed']),
            'style' => $this->kits->forUser($user)['collection'],
            'listings' => array_map(fn (array $l): array => ['ulid' => $l['ulid'], 'label' => $l['label']], $ads->listings($user)),
            'batch' => is_string($batch) ? Design::query()->where('user_id', $user->id)->where('batch', $batch)->orderBy('id')->get()->map($row)->values()->all() : [],
            'canva' => app(CanvaStudio::class)->connection($user) !== null,
            'recent' => Design::query()->where('user_id', $user->id)->where('status', 'ready')->latest('id')->limit(12)->get()->map($row)->values()->all(),
        ]);
    }

    public function makeDesigns(Request $request, Designer $designer): RedirectResponse
    {
        $made = $designer->create(self::user($request), $request->only(['moment', 'listing', 'headline', 'sub', 'body', 'by', 'when', 'stats', 'style']));

        return redirect('/studio/create?batch='.$made[0]->batch);
    }

    public function download(Request $request, string $design, Designer $designer): StreamedResponse
    {
        $user = self::user($request);
        $d = Design::query()->where('ulid', $design)->where('user_id', $user->id)->firstOrFail();
        if ($request->query('size') === 'carousel') {
            $paths = array_values(array_filter([$d->files['portrait'] ?? null, ...(array) ($d->files['slides'] ?? [])], 'is_string'));
            abort_if(count($paths) < 2, 404);
            $designer->use($d, $user);

            // The whole carousel in posting order, as one ZIP.
            return response()->streamDownload(function () use ($paths): void {
                $tmp = (string) tempnam(sys_get_temp_dir(), 'studio');
                $zip = new ZipArchive;
                $zip->open($tmp, ZipArchive::OVERWRITE);
                foreach ($paths as $i => $path) {
                    $zip->addFromString(sprintf('%02d.jpg', $i + 1), (string) Storage::disk('public')->get($path));
                }
                $zip->close();
                readfile($tmp);
                @unlink($tmp);
            }, 'rightally-'.$d->moment.'-carousel.zip', ['Content-Type' => 'application/zip']);
        }
        $size = isset(Designer::SIZES[(string) $request->query('size')]) ? (string) $request->query('size') : 'portrait';
        $designer->use($d, $user);

        return Storage::disk('public')->download((string) ($d->files[$size] ?? abort(404)), 'rightally-'.$d->moment.'-'.$size.'.jpg');
    }

    public function post(Request $request, string $design, Designer $designer): RedirectResponse
    {
        $user = self::user($request);
        $d = Design::query()->where('ulid', $design)->where('user_id', $user->id)->firstOrFail();
        $designer->use($d, $user);

        return redirect('/social?design='.$d->ulid);
    }

    private static function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User && in_array($user->kind, [UserKind::Owner, UserKind::Staff, UserKind::Agent], true), 403);

        return $user;
    }
}
