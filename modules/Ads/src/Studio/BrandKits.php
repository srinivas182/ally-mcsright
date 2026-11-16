<?php

declare(strict_types=1);

namespace Modules\Ads\Studio;

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Connect\AppRegistry;
use Modules\Core\Access\Can;
use Modules\Core\Audit\AuditLogger;
use Modules\Core\Connect\AppCatalog;
use Modules\Core\Identity\Enums\UserKind;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\TenantContext;
use Throwable;

/**
 * Brand kits (specs/grow/13, SA1a): the brokerage's (logo, colours, fonts, disclaimer, brand lock) and each agent's
 * (headshot with the background removed, name, title, licence line, phone, tagline, handles, languages). Every
 * design, video and caption uses the merged kit. Images are re-encoded on upload (no hidden data kept) and stored
 * publicly, because they appear in public posts.
 */
final class BrandKits
{
    /** @return array<string, string> the style library (specs/grow/13, SA1d): key => name */
    public static function collections(): array
    {
        return Styles::names();
    }

    public const FONTS = ['Playfair Display', 'Cormorant Garamond', 'Libre Baskerville', 'DM Serif Display', 'Inter', 'Montserrat', 'DM Sans', 'Lato'];

    public const LANGUAGES = ['en' => 'English', 'es' => 'Spanish'];

    public const DEFAULT_COLORS = ['primary' => '#111111', 'accent' => '#C9A227', 'ink' => '#111111', 'paper' => '#FFFFFF'];

    public const DETAILS = ['display_name' => 60, 'title' => 60, 'licence_line' => 120, 'phone' => 30, 'email' => 120, 'tagline' => 120, 'instagram' => 60, 'facebook' => 120,
        'linkedin' => 120, 'disclaimer' => 300];

    public function __construct(private readonly TenantContext $tenants) {}

    /**
     * The kit a design uses for this person: the brokerage's, with the person's own on top (unless locked).
     *
     * @return array{collection: string, colors: array<string, string>, fonts: array{heading: string, body: string}, logo: string|null, logo_dark: string|null,
     *     headshot: string|null, cutout: string|null, details: array<string, string>, languages: list<string>, brokerage: string, locked: bool, finish: string|null,
     *     favorites: list<string>, allowed: list<string>}
     */
    public function forUser(User $user): array
    {
        $b = $this->row(null);
        $a = $this->row($user->id);
        $locked = (bool) ($b->brand_lock ?? false);
        $pick = fn (string $col) => $this->json($locked ? null : ($a->{$col} ?? null)) ?? $this->json($b->{$col} ?? null);
        $details = array_merge(['display_name' => trim($user->first_name.' '.$user->last_name), 'phone' => (string) ($user->getAttribute('phone') ?? ''),
            'email' => (string) $user->email], array_filter($this->json($b->details ?? null) ?? []), array_filter($this->json($a->details ?? null) ?? []));

        // Locked: agents use the brokerage's style, or one of the styles the brokerage allows.
        $allowed = $locked ? array_values(array_filter(array_unique(array_map(fn (string $k): string => Styles::key($k), array_filter(array_map('strval', (array) ($this->json($b->styles ?? null) ?? [])), fn (string $k): bool => Styles::known($k)))))) : array_keys(Styles::ALL);
        $mine = $a->collection ?? null;
        $myFinish = $a->finish ?? null;
        $firmFinish = $b->finish ?? null;
        $style = $locked ? ($allowed !== [] && is_string($mine) && in_array(Styles::key($mine), $allowed, true) ? $mine : ($b->collection ?? null)) : ($mine ?? $b->collection ?? null);
        $favorites = array_values(array_unique(array_filter(array_map(fn (string $k): string => Styles::key($k), array_map('strval', (array) ($this->json($a->favorites ?? null) ?? []))),
            fn (string $k): bool => ! $locked || $allowed === [] || in_array($k, $allowed, true))));

        return [
            'collection' => Styles::key(is_string($style) ? $style : null),
            'finish' => ! $locked && is_string($myFinish) ? $myFinish : (is_string($firmFinish) ? $firmFinish : null),
            'favorites' => $favorites,
            'allowed' => $allowed === [] ? [Styles::key($b->collection ?? null)] : $allowed,
            'colors' => array_merge(self::DEFAULT_COLORS, (array) ($pick('colors') ?? [])),
            'fonts' => array_merge(['heading' => 'Playfair Display', 'body' => 'Inter'], (array) ($pick('fonts') ?? [])),
            'logo' => $this->url($b->logo_path ?? null), 'logo_dark' => $this->url($b->logo_dark_path ?? null),
            'headshot' => $this->url($a->headshot_path ?? null), 'cutout' => $this->url($a->cutout_path ?? null),
            'details' => array_map('strval', $details),
            'languages' => array_values((array) ($this->json($a->languages ?? null) ?? $this->json($b->languages ?? null) ?? ['en'])),
            'brokerage' => (string) $this->tenants->current()->name,
            'locked' => $locked,
        ];
    }

    /**
     * The brokerage's own kit, as saved (for the owner's form).
     *
     * @return array{collection: string|null, finish: string|null, styles: list<string>, colors: array<string, string>, fonts: array<string, string>, details: array<string, string>, logo: string|null, logo_dark: string|null, brand_lock: bool}
     */
    public function brokerage(): array
    {
        $b = $this->row(null);

        return ['collection' => $b->collection ?? null, 'finish' => $b->finish ?? null, 'styles' => array_values(array_map('strval', (array) ($this->json($b->styles ?? null) ?? []))),
            'colors' => (array) ($this->json($b->colors ?? null) ?? []), 'fonts' => (array) ($this->json($b->fonts ?? null) ?? []),
            'details' => array_map('strval', (array) ($this->json($b->details ?? null) ?? [])), 'logo' => $this->url($b->logo_path ?? null),
            'logo_dark' => $this->url($b->logo_dark_path ?? null), 'brand_lock' => (bool) ($b->brand_lock ?? false)];
    }

    /**
     * What is still missing for great designs (shown as a checklist).
     *
     * @return list<string>
     */
    public function missing(User $user): array
    {
        $k = $this->forUser($user);

        return array_values(array_filter([
            $k['headshot'] === null ? 'Your headshot' : null,
            $k['logo'] === null ? 'The brokerage logo'.(Can::manage($user, 'marketing.manage') ? '' : ' (your broker adds it)') : null,
            ($k['details']['licence_line'] ?? '') === '' ? 'Your licence line' : null,
            ($k['details']['phone'] ?? '') === '' ? 'Your phone number' : null,
        ]));
    }

    /**
     * Saves a kit: the person's own ($for = them), or the brokerage's ($for = null, owners only).
     *
     * @param  array<string, mixed>  $in  collection, colors[], fonts[], details[], languages[], brand_lock
     * @param  array<string, UploadedFile|null>  $files  headshot, logo, logo_dark
     */
    public function save(User $by, ?User $for, array $in, array $files): void
    {
        if ($for === null) {
            abort_unless($by->kind === UserKind::Owner, 403);
        } else {
            abort_unless($for->id === $by->id, 403);
        }
        $row = $this->row($for?->id);
        $locked = $for !== null && (bool) ($this->row(null)->brand_lock ?? false);
        $values = ['details' => json_encode($this->details((array) ($in['details'] ?? []))), 'languages' => json_encode(array_values(array_intersect(array_keys(self::LANGUAGES),
            array_map('strval', (array) ($in['languages'] ?? ['en'])))) ?: ['en'])];
        if (! $locked) {
            $chosen = (string) ($in['collection'] ?? '');
            $values += ['collection' => Styles::known($chosen) ? Styles::key($chosen) : null,
                'finish' => isset(Styles::FINISHES[$in['finish'] ?? '']) ? (string) $in['finish'] : null,
                'colors' => json_encode($this->colors((array) ($in['colors'] ?? []))) ?: null, 'fonts' => json_encode($this->fonts((array) ($in['fonts'] ?? []))) ?: null];
        }
        if ($for === null) {
            $values['brand_lock'] = (bool) ($in['brand_lock'] ?? false);
            $values['styles'] = json_encode(array_values(array_intersect(array_keys(Styles::ALL), array_map(fn (string $k): string => Styles::key($k), array_filter(array_map('strval', (array) ($in['styles'] ?? [])), fn (string $k): bool => Styles::known($k))))));
        } elseif (array_key_exists('favorites', $in)) {
            $values['favorites'] = json_encode(array_slice(array_values(array_intersect(array_keys(Styles::ALL), array_map(fn (string $k): string => Styles::key($k), array_filter(array_map('strval', (array) $in['favorites']), fn (string $k): bool => Styles::known($k))))), 0, 6));
        } elseif ($locked && Styles::known((string) ($in['collection'] ?? ''))) {
            $values['collection'] = Styles::key((string) $in['collection']); // checked against the allowed styles when used
        }
        foreach (['headshot' => $for !== null, 'logo' => $for === null, 'logo_dark' => $for === null] as $field => $allowed) {
            $file = $files[$field] ?? null;
            if ($allowed && $file instanceof UploadedFile) {
                $path = $this->store($file, $for?->id, $field);
                $values[$field.'_path'] = $path;
                if ($field === 'headshot') {
                    $values['cutout_path'] = $this->cutout($path);
                }
            }
        }
        $db = DB::connection(Connections::cell())->table('studio_brand_kits');
        $key = ['tenant_id' => $this->tenants->id(), 'user_id' => $for?->id];
        $row !== null ? (clone $db)->where('tenant_id', $key['tenant_id'])->where(fn ($q) => $for === null ? $q->whereNull('user_id') : $q->where('user_id', $for->id))
            ->update($values + ['updated_at' => now()]) : $db->insert($key + $values + ['created_at' => now(), 'updated_at' => now()]);
        app(AuditLogger::class)->record('studio.brand_kit_saved', metadata: ['for' => $for === null ? 'brokerage' : 'agent', 'images' => array_keys(array_filter($files))]);
    }

    /**
     * Saves the person's style choices without touching the rest of their kit: their style and finish (unless the
     * brokerage locks the brand to styles that exclude it) and the favourites Autopilot rotates through.
     *
     * @param  list<string>  $favorites
     */
    public function saveStyle(User $user, string $style, ?string $finish, array $favorites): void
    {
        $kit = $this->forUser($user);
        $style = Styles::key($style);
        if (! in_array($style, $kit['allowed'], true)) {
            throw ValidationException::withMessages(['collection' => 'Your brokerage uses its own styles. Choose one of them.']);
        }
        $values = ['collection' => $style, 'finish' => isset(Styles::FINISHES[$finish ?? '']) ? $finish : null,
            'favorites' => json_encode(array_slice(array_values(array_unique(array_intersect(array_map(fn (string $k): string => Styles::key($k), array_filter($favorites, fn (string $k): bool => Styles::known($k))), $kit['allowed']))), 0, 6)),
            'updated_at' => now()];
        $db = DB::connection(Connections::cell())->table('studio_brand_kits');
        $this->row($user->id) !== null ? (clone $db)->where('tenant_id', $this->tenants->id())->where('user_id', $user->id)->update($values)
            : $db->insert(['tenant_id' => $this->tenants->id(), 'user_id' => $user->id, 'created_at' => now()] + $values);
        app(AuditLogger::class)->record('studio.style_saved', metadata: ['style' => $style, 'finish' => $values['finish']]);
    }

    /** Whether a background-removal service is switched on by HQ. */
    public function cutoutReady(): bool
    {
        $app = app(AppCatalog::class)->find('studio_cutout');

        return $app !== null && app(AppRegistry::class)->ready($app) && (app(AppRegistry::class)->secrets($app)['api_key'] ?? '') !== '';
    }

    /** @return array{logo: string|null, headshot: string|null} the brokerage logo and the person's headshot, as stored (public disk) */
    public function paths(User $user): array
    {
        $b = $this->row(null);
        $a = $this->row($user->id);
        $logo = $b->logo_path ?? null;
        $headshot = $a->headshot_path ?? null;

        return ['logo' => is_string($logo) && $logo !== '' ? $logo : null, 'headshot' => is_string($headshot) && $headshot !== '' ? $headshot : null];
    }

    /**
     * A square PNG (1200x1200) for ads that need one (YouTube ads, specs/ads/05): the brokerage logo, or the agent's
     * headshot when there is no logo, contained on white with a margin. Null when there is neither.
     */
    public function squareLogo(User $user): ?string
    {
        $disk = Storage::disk('public');
        $b = $this->row(null);
        $a = $this->row($user->id);
        $logoPath = $b->logo_path ?? null;
        $headshotPath = $a->headshot_path ?? null;
        $logo = is_string($logoPath) && $logoPath !== '' && $disk->exists($logoPath) ? $logoPath : null;
        $headshot = is_string($headshotPath) && $headshotPath !== '' && $disk->exists($headshotPath) ? $headshotPath : null;
        $path = $logo ?? $headshot;
        $src = $path !== null ? @imagecreatefromstring((string) $disk->get($path)) : false;
        if ($src === false) {
            return null;
        }
        $size = 1200;
        $canvas = imagecreatetruecolor($size, $size);
        imagefill($canvas, 0, 0, (int) imagecolorallocate($canvas, 255, 255, 255));
        [$w, $h] = [imagesx($src), imagesy($src)];
        if ($logo === null) {
            $side = min($w, $h); // a headshot fills the square, cropped from the top third
            imagecopyresampled($canvas, $src, 0, 0, (int) (($w - $side) / 2), (int) max(0, ($h - $side) * 0.2), $size, $size, $side, $side);
        } else {
            $scale = min(($size * 0.8) / max(1, $w), ($size * 0.8) / max(1, $h));
            [$dw, $dh] = [(int) round($w * $scale), (int) round($h * $scale)];
            imagecopyresampled($canvas, $src, (int) (($size - $dw) / 2), (int) (($size - $dh) / 2), 0, 0, $dw, $dh, $w, $h);
        }
        ob_start();
        imagepng($canvas);

        return (string) ob_get_clean();
    }

    private function row(?int $userId): ?object
    {
        return DB::connection(Connections::cell())->table('studio_brand_kits')->where('tenant_id', $this->tenants->id())
            ->where(fn ($q) => $userId === null ? $q->whereNull('user_id') : $q->where('user_id', $userId))->first();
    }

    /** @return array<string, mixed>|null */
    private function json(mixed $raw): ?array
    {
        $v = is_string($raw) ? json_decode($raw, true) : null;

        return is_array($v) && $v !== [] ? $v : null;
    }

    private function url(?string $path): ?string
    {
        return $path !== null && $path !== '' ? Storage::disk('public')->url($path) : null;
    }

    /**
     * @param  array<mixed>  $in
     * @return array<string, string>
     */
    private function details(array $in): array
    {
        $out = [];
        foreach (self::DETAILS as $k => $max) {
            $v = trim(strip_tags((string) ($in[$k] ?? '')));
            if ($v !== '') {
                $out[$k] = mb_substr($v, 0, $max);
            }
        }

        return $out;
    }

    /**
     * @param  array<mixed>  $in
     * @return array<string, string>
     */
    private function colors(array $in): array
    {
        $out = [];
        foreach (array_keys(self::DEFAULT_COLORS) as $k) {
            if (is_string($in[$k] ?? null) && preg_match('/^#[0-9a-fA-F]{6}$/', $in[$k]) === 1) {
                $out[$k] = strtoupper($in[$k]);
            }
        }

        return $out;
    }

    /**
     * @param  array<mixed>  $in
     * @return array<string, string>
     */
    private function fonts(array $in): array
    {
        return array_filter(['heading' => in_array($in['heading'] ?? null, self::FONTS, true) ? (string) $in['heading'] : null,
            'body' => in_array($in['body'] ?? null, self::FONTS, true) ? (string) $in['body'] : null]);
    }

    /** Re-encodes the image (no hidden data, at most 2,000 px) and stores it. */
    private function store(UploadedFile $file, ?int $userId, string $kind): string
    {
        $raw = (string) file_get_contents($file->getRealPath());
        $img = in_array($file->getMimeType(), ['image/jpeg', 'image/png', 'image/webp'], true) && strlen($raw) <= 10 * 1024 * 1024 ? @imagecreatefromstring($raw) : false;
        if ($img === false) {
            throw ValidationException::withMessages([$kind => 'Use a JPG, PNG or WebP image under 10 MB.']);
        }
        [$w, $h] = [imagesx($img), imagesy($img)];
        if ($kind === 'headshot' && min($w, $h) < 400) {
            throw ValidationException::withMessages([$kind => 'Use a headshot at least 400 pixels wide, so it stays sharp in posts.']);
        }
        $scale = min(1, 2000 / max($w, $h));
        if ($scale < 1) {
            $img = imagescale($img, (int) round($w * $scale), (int) round($h * $scale)) ?: $img;
        }
        imagesavealpha($img, true);
        ob_start();
        imagepng($img, null, 6);
        $path = 'studio/'.strtolower($this->tenants->current()->ulid).'/'.($userId ?? 'brokerage').'/'.$kind.'-'.Str::lower(Str::random(12)).'.png';
        Storage::disk('public')->put($path, (string) ob_get_clean(), ['visibility' => 'public']);

        return $path;
    }

    /** The headshot with its background removed (transparent PNG), or null when the service is off or fails. */
    private function cutout(string $path): ?string
    {
        if (! $this->cutoutReady()) {
            return null;
        }
        $key = (string) app(AppRegistry::class)->secrets(app(AppCatalog::class)->find('studio_cutout') ?? abort(500))['api_key'];
        try {
            $r = Http::withHeaders(['X-Api-Key' => $key])->timeout(60)->attach('image_file', (string) Storage::disk('public')->get($path), 'headshot.png')
                ->post('https://api.remove.bg/v1.0/removebg', ['size' => 'auto', 'format' => 'png']);
        } catch (Throwable $e) {
            report($e);

            return null;
        }
        if (! $r->successful() || ! str_starts_with((string) $r->header('Content-Type'), 'image/')) {
            return null;
        }
        $out = preg_replace('/\.png$/', '-cutout.png', $path) ?? $path.'-cutout.png';
        Storage::disk('public')->put($out, $r->body(), ['visibility' => 'public']);

        return $out;
    }
}
