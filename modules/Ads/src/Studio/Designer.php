<?php

declare(strict_types=1);

namespace Modules\Ads\Studio;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Ads\Jobs\RenderDesign;
use Modules\Ads\ListingAds;
use Modules\Ads\Studio\Render\StudioRenderer;
use Modules\Core\Audit\AuditLogger;
use Modules\Core\Identity\Enums\UserKind;
use Modules\Core\Tenancy\TenantContext;
use Modules\Crm\FairHousing;
use Modules\Listings\Media\Photos;
use Modules\Listings\Models\Listing;
use Modules\Listings\Models\ListingMedia;
use Modules\Listings\Search\ListingSearch;
use Throwable;

/**
 * The Ally Studio designer (specs/grow/13, SA1b): for a moment (a listing's news, a market update, a testimonial,
 * the agent), three designs in the person's brand, each in three sizes, checked by the art director. Making designs
 * is free; one counts against the allowance (or credits) when it is first downloaded or posted.
 */
final class Designer
{
    /** moment => [label, kind (listing|text|agent), listing statuses it fits (null: any on-market)] */
    public const MOMENTS = [
        'just_listed' => ['Just listed', 'listing', ['Active']],
        'open_house' => ['Open house', 'listing', ['Active', 'Coming Soon']],
        'price_improved' => ['Price improvement', 'listing', ['Active']],
        'coming_soon' => ['Coming to market', 'listing', ['Coming Soon']],
        'under_contract' => ['Under contract', 'listing', ['Pending', 'Active Under Contract']],
        'just_sold' => ['Just sold', 'listing', ['Closed']],
        'listing_feature' => ['Inside', 'listing', ['Active', 'Coming Soon']],
        'neighbourhood' => ['The neighbourhood', 'listing', ['Active', 'Coming Soon']],
        'just_closed' => ['Welcome home', 'agent', null],
        'market_update' => ['Market update', 'text', null],
        'testimonial' => ['Client words', 'text', null],
        'tip' => ['Tip', 'text', null],
        'holiday' => ['Season\'s greetings', 'text', null],
        'milestone' => ['Milestone', 'agent', null],
        'meet_agent' => ['Meet your agent', 'agent', null],
        'recruiting' => ['Join our team', 'agent', null],
    ];

    /** size => [width, height, type scale] */
    public const SIZES = ['square' => [1080, 1080, 0.92], 'portrait' => [1080, 1350, 1.0], 'story' => [1080, 1920, 1.08]];

    /** Font files bundled in modules/Ads/resources/fonts (SIL OFL). */
    private const FONT_FILES = ['Playfair Display' => ['playfair-display', [600, 700]], 'Cormorant Garamond' => ['cormorant-garamond', [500, 600]],
        'Libre Baskerville' => ['libre-baskerville', [400, 700]], 'DM Serif Display' => ['dm-serif-display', [400]], 'Inter' => ['inter', [400, 600]],
        'Montserrat' => ['montserrat', [400, 600]], 'DM Sans' => ['dm-sans', [400, 600]], 'Lato' => ['lato', [400, 700]]];

    public function __construct(private readonly StudioPlans $plans, private readonly BrandKits $kits, private readonly ArtDirector $director,
        private readonly TenantContext $tenants) {}

    /**
     * The moments this person can design for.
     *
     * @return array<string, string>
     */
    public function moments(User $user): array
    {
        $out = [];
        foreach (self::MOMENTS as $key => [$label]) {
            if ($key !== 'recruiting' || (in_array($user->kind, [UserKind::Owner, UserKind::Staff], true) && $this->plans->has($user, 'recruiting'))) {
                $out[$key] = $label;
            }
        }

        return $out;
    }

    /**
     * Makes three designs for a moment and starts rendering them.
     *
     * @param  array<string, mixed>  $in  moment, listing, headline, sub, body, by, when, stats[] (value, label)
     * @return list<Design>
     */
    public function create(User $by, array $in): array
    {
        if ($this->plans->current($by) === null) {
            throw ValidationException::withMessages(['moment' => 'Ally Studio is an add-on. Choose a Studio package first.']);
        }
        $moment = (string) ($in['moment'] ?? '');
        if (! isset($this->moments($by)[$moment])) {
            throw ValidationException::withMessages(['moment' => 'Choose what the post is about.']);
        }
        $today = Design::query()->where('user_id', $by->id)->where('created_at', '>=', CarbonImmutable::now()->startOfDay())->distinct()->count('batch');
        if ($today >= (int) config('studio.daily_sets', 40)) {
            throw ValidationException::withMessages(['moment' => 'That\'s a lot of designs for one day. Try again tomorrow.']);
        }
        [$label, $kind] = self::MOMENTS[$moment];
        $kit = $this->kits->forUser($by);
        $content = ['kicker' => $label] + $this->words($in);
        $photos = [];
        $listing = null;
        if ($kind === 'listing') {
            [$listing, $facts, $photos] = $this->listing($by, (string) ($in['listing'] ?? ''), $moment, max(0, (int) ($in['photo_from'] ?? 0)));
            if ($moment === 'neighbourhood') {
                // About the area, not the home: no price or room counts.
                $facts = ['headline' => (string) ($facts['area'] ?? $facts['sub'] ?? ''), 'sub' => 'Around '.$facts['headline'], 'attribution' => $facts['attribution'] ?? null];
            }
            unset($facts['area']);
            $content = $content + $facts; // the person's words win
            if ($moment === 'open_house') {
                $when = trim(strip_tags((string) ($in['when'] ?? '')));
                if ($when === '') {
                    throw ValidationException::withMessages(['when' => 'When is the open house? For example: Sunday, June 7 · 1–3 PM.']);
                }
                $content['sub'] = mb_substr($when, 0, 60).' · '.($facts['sub'] ?? '');
            }
        } elseif ($kind === 'agent' && $kit['cutout'] === null && $kit['headshot'] === null) {
            throw ValidationException::withMessages(['moment' => 'Add your headshot to the brand kit first: this design is about you.']);
        }
        if (($content['headline'] ?? '') === '') {
            throw ValidationException::withMessages(['headline' => 'Write a short headline.']);
        }
        $problems = $this->director->wordsProblems($content);
        $flags = FairHousing::screen(implode(' ', array_filter([(string) ($content['headline'] ?? ''), (string) ($content['sub'] ?? ''), (string) ($content['body'] ?? '')])),
            'ads', 'blocked', 'Ally Studio design');
        if ($flags !== []) {
            $problems[] = 'Fair Housing: please remove "'.implode('", "', $flags).'". Describe the home and the area, not who should live there.';
        }
        if ($problems !== []) {
            throw ValidationException::withMessages(['headline' => $problems[0]]);
        }

        // Three designs: for a listing, three layouts on three different photos; otherwise three collections (one if brand-locked).
        $plans = $kind === 'listing'
            ? array_map(fn (string $v, int $i): array => [$kit['collection'], $v, $photos[$i % count($photos)]], ['full', 'split', 'frame'], [0, 1, 2])
            : array_map(fn (string $col): array => [$col, 'type', null], $kit['locked'] ? [$kit['collection']]
                : array_slice(array_values(array_unique([$kit['collection'], ...array_keys(BrandKits::COLLECTIONS)])), 0, 3));
        $batch = strtolower((string) Str::ulid());
        $made = [];
        foreach ($plans as [$collection, $variant, $photo]) {
            $made[] = $d = Design::query()->create(['user_id' => $by->id, 'batch' => $batch, 'moment' => $moment, 'listing_ulid' => $listing, 'collection' => $collection,
                'variant' => $variant, 'content' => $content + ['photo' => $photo], 'status' => 'rendering']);
            RenderDesign::dispatch($d->id);
        }
        app(AuditLogger::class)->record('studio.designs_made', metadata: ['moment' => $moment, 'listing' => $listing, 'designs' => count($made)]);

        return array_map(fn (Design $d): Design => $d->fresh() ?? $d, $made);
    }

    /** Renders a design in its three sizes; a design the art director rejects is marked failed with why. */
    public function render(Design $design): void
    {
        $user = User::query()->find($design->user_id);
        if ($user === null) {
            return;
        }
        $kit = $this->kits->forUser($user);
        $p = $this->director->palette($kit['colors'], $design->collection);
        $dark = ArtDirector::luminance($p['bg']) < 0.4;
        $kind = self::MOMENTS[$design->moment][1] ?? 'text';
        $files = [];
        $checks = [];
        try {
            foreach (self::SIZES as $size => [$w, $h, $scale]) {
                $html = view('ads::studio.design', ['w' => $w, 'h' => $h, 'scale' => $scale, 'variant' => $design->variant, 'type' => $kind, 'collection' => $design->collection,
                    'p' => $p, 'kit' => $kit, 'c' => $design->content, 'fonts' => $this->fontFaces($kit['fonts']), 'hero' => $design->content['photo'] ?? null,
                    'agent' => $kit['cutout'] ?? $kit['headshot'], 'logo' => $dark ? ($kit['logo_dark'] ?? null) : ($kit['logo'] ?? $kit['logo_dark'])])->render();
                $png = app(StudioRenderer::class)->render($html, $w, $h);
                if ($this->director->looksBlank($png)) {
                    $checks[] = "The {$size} design came out blank.";

                    continue;
                }
                $img = imagecreatefromstring($png);
                if ($img === false) {
                    $checks[] = "The {$size} design could not be read.";

                    continue;
                }
                ob_start();
                imagejpeg($img, null, 90);
                $path = 'studio/'.strtolower($this->tenants->current()->ulid).'/designs/'.strtolower($design->ulid).'-'.$size.'.jpg';
                Storage::disk('public')->put($path, (string) ob_get_clean(), ['visibility' => 'public']);
                $files[$size] = $path;
            }
        } catch (Throwable $e) {
            report($e);
            $checks[] = 'The design couldn\'t be drawn just now. Please try again.';
        }
        $ok = count($files) === count(self::SIZES);
        $design->forceFill(['files' => $files, 'checks' => $checks, 'status' => $ok ? 'ready' : 'failed'])->save();
    }

    /**
     * The first download or post of a design counts once against this month's allowance (or credits).
     */
    public function use(Design $design, User $by): void
    {
        abort_unless($design->user_id === $by->id && $design->status === 'ready', 404);
        if ($design->used_at === null) {
            $this->plans->use($by, 'post', $design->ulid);
            $design->forceFill(['used_at' => CarbonImmutable::now()])->save();
        }
    }

    public function url(Design $design, string $size): ?string
    {
        $path = $design->files[$size] ?? null;
        $cdn = config('listings.cdn_url');

        return $path === null ? null : (is_string($cdn) && $cdn !== '' ? rtrim($cdn, '/').'/'.$path : Storage::disk('public')->url($path));
    }

    /** A plain caption to start from (Studio's AI captions arrive with Autopilot, SA2). */
    public function caption(Design $design): string
    {
        $c = $design->content;
        $lines = array_filter([
            (string) ($c['kicker'] ?? '').': '.(string) ($c['headline'] ?? ''),
            (string) ($c['sub'] ?? ''),
            implode(' · ', array_filter([(string) ($c['price'] ?? ''), ...array_map('strval', (array) ($c['facts'] ?? []))])),
            (string) ($c['body'] ?? ''),
            isset($c['by']) && $c['by'] !== '' ? '— '.$c['by'] : '',
        ]);

        return trim(implode("\n", $lines));
    }

    /**
     * @param  array<string, mixed>  $in
     * @return array<string, mixed>
     */
    private function words(array $in): array
    {
        $t = fn (string $k, int $max): string => mb_substr(trim(strip_tags((string) ($in[$k] ?? ''))), 0, $max);
        $stats = [];
        foreach (array_slice((array) ($in['stats'] ?? []), 0, 3) as $s) {
            if (is_array($s) && trim((string) ($s['value'] ?? '')) !== '') {
                $stats[] = ['value' => mb_substr(trim(strip_tags((string) $s['value'])), 0, 12), 'label' => mb_substr(trim(strip_tags((string) ($s['label'] ?? ''))), 0, 30)];
            }
        }

        return array_filter(['headline' => $t('headline', 80), 'sub' => $t('sub', 80), 'body' => $t('body', 400), 'by' => $t('by', 40), 'stats' => $stats],
            fn ($v): bool => $v !== '' && $v !== []);
    }

    /**
     * One of the person's listings, its facts and its best photos.
     *
     * @return array{0: string, 1: array<string, mixed>, 2: list<string>}
     */
    private function listing(User $by, string $ulid, string $moment, int $from = 0): array
    {
        $home = app(ListingAds::class)->listing($by, $ulid) ?? throw ValidationException::withMessages(['listing' => 'Choose one of your listings.']);
        $fits = self::MOMENTS[$moment][2] ?? null;
        if ($fits !== null && ! in_array((string) ($home['status'] ?? ''), $fits, true)) {
            throw ValidationException::withMessages(['listing' => 'This listing is '.mb_strtolower((string) ($home['status'] ?? 'not on the market'))
                .' in the MLS, so a "'.self::MOMENTS[$moment][0].'" design would not be accurate.']);
        }
        $listing = Listing::query()->where('ulid', $ulid)->firstOrFail();
        $disk = Storage::disk((string) config('listings.media_disk', 'public'));
        $hero = (int) (config('listings.sizes')['hero'] ?? 1600);
        $photos = [];
        $media = ListingMedia::query()->where('listing_id', $listing->id)->where('status', 'stored')->orderBy('position')->limit(12)->get();
        $media = $media->slice(min($from, max(0, $media->count() - 1)))->concat($media->take(min($from, max(0, $media->count() - 1)))); // start further in, wrap round
        foreach ($media as $m) {
            $bytes = $m->storage_path !== null ? $disk->get($m->storage_path.'-'.$hero.'.webp') : null;
            if (is_string($bytes) && $this->director->photoProblem($bytes) === null) {
                $photos[] = (string) Photos::url($m->storage_path, 'hero');
            }
            if (count($photos) === 3) {
                break;
            }
        }
        if ($photos === []) {
            throw ValidationException::withMessages(['listing' => 'This listing\'s photos aren\'t large or sharp enough for a premium design yet.']);
        }
        $baths = is_numeric($home['baths_full'] ?? null) ? (int) $home['baths_full'] + (is_numeric($home['baths_half'] ?? null) && (int) $home['baths_half'] > 0 ? 0.5 : 0) : null;
        $facts = array_values(array_filter([is_numeric($home['beds'] ?? null) ? $home['beds'].' Beds' : null, $baths !== null ? $baths.' Baths' : null,
            is_numeric($home['sqft'] ?? null) ? number_format((int) $home['sqft']).' Sq Ft' : null]));
        $street = is_string($home['address'] ?? null) && $home['address'] !== '' ? $home['address'] : ListingSearch::label($home);

        return [$ulid, array_filter(['headline' => $street, 'sub' => trim(implode(', ', array_filter([$home['city'] ?? null, trim(($home['state'] ?? '').' '.($home['postal_code'] ?? ''))]))),
            'facts' => $facts, 'price' => is_numeric($home['price'] ?? null) && $moment !== 'under_contract' ? '$'.number_format((int) $home['price']) : null,
            'attribution' => is_string($home['attribution'] ?? null) ? $home['attribution'] : null,
            'area' => is_string($home['subdivision'] ?? null) && $home['subdivision'] !== '' ? $home['subdivision'] : ($home['city'] ?? null)]), $photos];
    }

    /** @param array{heading: string, body: string} $fonts */
    private function fontFaces(array $fonts): string
    {
        $css = '';
        foreach (array_unique([$fonts['heading'], $fonts['body']]) as $family) {
            [$slug, $weights] = self::FONT_FILES[$family] ?? self::FONT_FILES['Inter'];
            foreach ($weights as $w) {
                $file = dirname(__DIR__, 2).'/resources/fonts/'.$slug.'-latin-'.$w.'-normal.woff2';
                $css .= "@font-face{font-family:'".$family."';font-weight:".($w >= 600 ? '600 900' : '100 500').";src:url('file://".$file."') format('woff2')}";
            }
        }

        return $css;
    }
}
