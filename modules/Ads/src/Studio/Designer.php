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

    /** The type system, bundled in modules/Ads/resources/fonts (SIL OFL): family => [[file, weight, style]]. */
    private const FONT_FILES = [
        'Cormorant Garamond' => [['cormorant-garamond-latin-300-normal', 300, 'normal'], ['cormorant-garamond-latin-500-normal', 500, 'normal'],
            ['cormorant-garamond-latin-300-italic', 300, 'italic'], ['cormorant-garamond-latin-400-italic', 400, 'italic'], ['cormorant-garamond-latin-500-italic', 500, 'italic']],
        'Bodoni Moda' => [['bodoni-moda-latin-400-normal', 400, 'normal'], ['bodoni-moda-latin-500-normal', 500, 'normal'], ['bodoni-moda-latin-400-italic', 400, 'italic']],
        'Cinzel' => [['cinzel-latin-400-normal', 400, 'normal'], ['cinzel-latin-500-normal', 500, 'normal']],
        'Jost' => [['jost-latin-300-normal', 300, 'normal'], ['jost-latin-400-normal', 400, 'normal'], ['jost-latin-500-normal', 500, 'normal']],
        'Pinyon Script' => [['pinyon-script-latin-400-normal', 400, 'normal']],
        'Italiana' => [['italiana-latin-400-normal', 400, 'normal']],
        'Playfair Display' => [['playfair-display-latin-600-normal', 600, 'normal'], ['playfair-display-latin-700-normal', 700, 'normal'], ['playfair-display-latin-400-italic', 400, 'italic']],
        'Tenor Sans' => [['tenor-sans-latin-400-normal', 400, 'normal']],
        'Libre Caslon Text' => [['libre-caslon-text-latin-400-normal', 400, 'normal'], ['libre-caslon-text-latin-700-normal', 700, 'normal'], ['libre-caslon-text-latin-400-italic', 400, 'italic']],
        'Montserrat' => [['montserrat-latin-300-normal', 300, 'normal'], ['montserrat-latin-400-normal', 400, 'normal'], ['montserrat-latin-600-normal', 600, 'normal']],
        'DM Serif Display' => [['dm-serif-display-latin-400-normal', 400, 'normal'], ['dm-serif-display-latin-400-italic', 400, 'italic']],
        'DM Sans' => [['dm-sans-latin-400-normal', 400, 'normal'], ['dm-sans-latin-600-normal', 600, 'normal']],
        'Syne' => [['syne-latin-600-normal', 600, 'normal'], ['syne-latin-800-normal', 800, 'normal']],
        'IBM Plex Mono' => [['ibm-plex-mono-latin-400-normal', 400, 'normal'], ['ibm-plex-mono-latin-500-normal', 500, 'normal']],
        'Bebas Neue' => [['bebas-neue-latin-400-normal', 400, 'normal']],
        'Marcellus' => [['marcellus-latin-400-normal', 400, 'normal']],
        'Libre Baskerville' => [['libre-baskerville-latin-400-normal', 400, 'normal'], ['libre-baskerville-latin-700-normal', 700, 'normal']],
    ];

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
        // Three designs: for a listing, the collection's three signature layouts, each leading with a different photo; otherwise three
        // collections (one if brand-locked). A gallery needs three good photos, so with fewer it gives way to the cover.
        // A style asked for (the style picker, Autopilot's rotation) is used when the person may use it.
        $style = is_string($in['style'] ?? null) && isset(Styles::ALL[$in['style']]) && in_array($in['style'], $kit['allowed'], true) ? $in['style'] : $kit['collection'];
        $layouts = array_map(fn (string $l): string => $l === 'gallery' && count($photos) < 3 ? 'cover' : $l, (array) Styles::get($style)['layouts']);
        $layouts = array_values(array_unique($layouts));
        $styles = array_slice(array_values(array_unique(array_filter([$style, ...$kit['favorites'], ...Styles::match([$style])],
            fn (string $k): bool => in_array($k, $kit['allowed'], true)))), 0, 3);
        $plans = $kind === 'listing'
            ? array_map(fn (string $l, int $i): array => [$style, $l, [...array_slice($photos, $i % max(1, count($photos))), ...array_slice($photos, 0, $i % max(1, count($photos)))]],
                $layouts, array_keys($layouts))
            : array_map(fn (string $col): array => [$col, 'statement', []], $styles);
        $batch = strtolower((string) Str::ulid());
        $made = [];
        foreach ($plans as [$collection, $variant, $shots]) {
            $made[] = $d = Design::query()->create(['user_id' => $by->id, 'batch' => $batch, 'moment' => $moment, 'listing_ulid' => $listing, 'collection' => $collection,
                'variant' => $variant, 'content' => $content + ['moment' => $moment, 'photo' => $shots[0] ?? null, 'photos' => array_slice($shots, 0, 3)], 'status' => 'rendering']);
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
        $kind = self::MOMENTS[$design->moment][1] ?? 'text';
        $files = [];
        $checks = [];
        try {
            foreach (self::SIZES as $size => [$w, $h]) {
                $html = $this->page($kit, $design->collection, $design->variant, $kind, $design->content + ['moment' => $design->moment],
                    array_values(array_filter((array) ($design->content['photos'] ?? [$design->content['photo'] ?? null]))), $w, $h);
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
     * One design page (HTML) for a brand kit, style and layout at a size: what the renderer draws.
     *
     * @param  array<string, mixed>  $kit  BrandKits::forUser()
     * @param  array<string, mixed>  $content
     * @param  list<string>  $photos
     */
    public function page(array $kit, string $style, string $layout, string $kind, array $content, array $photos, int $w, int $h): string
    {
        $p = $this->director->palette((array) $kit['colors'], $style, is_string($kit['finish'] ?? null) ? $kit['finish'] : null);
        $words = preg_split('/\s+/', trim((string) $kit['brokerage'])) ?: [];
        $monogram = mb_strtoupper(implode('', array_map(fn (string $w): string => mb_substr($w, 0, 1),
            array_slice(array_values(array_filter($words, fn (string $w): bool => ! in_array(mb_strtolower($w), ['the', 'of', 'and', '&'], true))), 0, 2))));

        return view('ads::studio.design', ['w' => $w, 'h' => $h, 'layout' => $layout, 'type' => $kind, 'collection' => Styles::key($style), 'p' => $p, 't' => Styles::get($style),
            'kit' => $kit, 'c' => $content, 'fonts' => self::fontFaces(), 'photos' => $photos, 'agent' => $kit['cutout'] ?? $kit['headshot'], 'monogram' => $monogram !== '' ? $monogram : 'R',
            'logo' => in_array($layout, ['cover', 'masthead', 'mono', 'colorfield'], true) || $p['dark'] ? ($kit['logo_dark'] ?? null) : ($kit['logo'] ?? $kit['logo_dark'])])->render();
    }

    /**
     * Sample content for previews of every style: the person's newest listing with good photos, otherwise a
     * "meet your agent" statement.
     *
     * @return array{kind: string, content: array<string, mixed>, photos: list<string>}
     */
    public function sample(User $user): array
    {
        foreach (array_slice(app(ListingAds::class)->listings($user), 0, 3) as $l) {
            try {
                [, $facts, $photos] = $this->listing($user, (string) $l['ulid'], 'open_house');
                unset($facts['area']);

                return ['kind' => 'listing', 'content' => ['kicker' => 'Just listed', 'moment' => 'just_listed'] + $facts, 'photos' => $photos];
            } catch (ValidationException) {
                continue;
            }
        }
        $name = trim($user->first_name.' '.$user->last_name);

        return ['kind' => 'agent', 'content' => ['kicker' => 'Meet your agent', 'moment' => 'meet_agent', 'headline' => 'Meet '.$user->first_name,
            'body' => 'Helping clients buy and sell with care, skill and a little magic.'], 'photos' => []];
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
            if (count($photos) === 5) {
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

    /** The premium type system every design uses (bundled, SIL OFL). */
    public static function fontFaces(): string
    {
        $css = '';
        foreach (self::FONT_FILES as $family => $faces) {
            foreach ($faces as [$file, $weight, $style]) {
                $css .= "@font-face{font-family:'".$family."';font-weight:".$weight.';font-style:'.$style.";src:url('file://".dirname(__DIR__, 2).'/resources/fonts/'.$file.".woff2') format('woff2')}";
            }
        }

        return $css;
    }
}
