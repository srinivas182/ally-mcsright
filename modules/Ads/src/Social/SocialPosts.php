<?php

declare(strict_types=1);

namespace Modules\Ads\Social;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Modules\Ads\Jobs\PublishSocialPost;
use Modules\Ads\ListingAds;
use Modules\Connect\AppRegistry;
use Modules\Core\Audit\AuditLogger;
use Modules\Core\Connect\AppCatalog;
use Modules\Core\Connect\ConnectorKit;
use Modules\Core\Connect\NeedsReconnect;
use Modules\Core\Identity\Enums\UserKind;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\TenantContext;
use Modules\Crm\FairHousing;
use Modules\Listings\Models\Listing;
use Modules\Listings\Search\ListingSearch;
use Throwable;

/**
 * Social posting (specs/connect/19, C7d-1): one post, written once, to Facebook Pages, Instagram, LinkedIn and
 * Google Business Profile, now or scheduled.
 *
 * - Places: the person's own connections; owners and staff also the brokerage's.
 * - Fair Housing checked (channel ads); the agent and brokerage are named; listing posts only for listings the
 *   person may advertise (the same rule as Ads), with the MLS attribution line.
 * - Each place gets its own link tag (utm_source = the network), goes out once, and keeps its result.
 */
final class SocialPosts
{
    /** @var array<string, class-string<SocialChannel>> */
    public const APPS = ['facebook_pages' => FacebookPages::class, 'linkedin' => LinkedIn::class, 'google_business' => GoogleBusiness::class, 'youtube' => YouTube::class];

    public const CHANNELS = ['facebook' => 'Facebook', 'instagram' => 'Instagram', 'linkedin' => 'LinkedIn', 'google' => 'Google', 'youtube' => 'YouTube'];

    public const MAX_TEXT = 2_800;

    public function __construct(private readonly ConnectorKit $kit, private readonly TenantContext $tenants, private readonly ListingAds $ads) {}

    /**
     * The social apps, whether HQ switched them on, and whether this person has one connected (for "Connect" links).
     *
     * @return list<array{key: string, name: string, ready: bool, connected: bool}>
     */
    public function apps(User $user): array
    {
        $out = [];
        foreach (array_keys(self::APPS) as $key) {
            $app = app(AppCatalog::class)->find($key);
            $out[] = ['key' => $key, 'name' => $app->name ?? $key, 'ready' => $app !== null && app(AppRegistry::class)->ready($app),
                'connected' => collect($this->kit->connections($key))->contains(fn ($c): bool => $this->mayUse($c->ownerUserId, $user))];
        }

        return $out;
    }

    /**
     * Every place this person may post to.
     *
     * @return list<array{key: string, channel: string, id: string, name: string, connection_id: int, brokerage: bool}>
     */
    public function places(User $user): array
    {
        $out = [];
        foreach (self::APPS as $key => $driver) {
            foreach ($this->kit->connections($key) as $c) {
                if (! $this->mayUse($c->ownerUserId, $user)) {
                    continue;
                }
                foreach (app($driver)->places($c) as $p) {
                    $out[] = ['key' => $p['channel'].':'.$c->ulid.':'.$p['id'], 'channel' => $p['channel'], 'id' => $p['id'], 'name' => $p['name'], 'connection_id' => $c->id,
                        'brokerage' => $c->ownerUserId === null];
                }
            }
        }

        return $out;
    }

    /**
     * A starting point from one of the person's listings: a line, its website page and its photo (as a JPEG, which
     * Instagram needs).
     *
     * @return array{listing: string, text: string, link: string, image: string|null}
     */
    public function fromListing(User $user, string $ulid): array
    {
        $home = $this->ads->listing($user, $ulid) ?? throw ValidationException::withMessages(['listing' => 'Choose one of your listings.']);
        $label = ListingSearch::label($home);
        $link = ListingSearch::publicUrl($ulid, $label) ?? throw ValidationException::withMessages(['listing' => 'Listing posts link to the listing on your website, which isn\'t live yet.']);
        $facts = array_filter([is_numeric($home['price'] ?? null) ? '$'.number_format((int) $home['price']) : null, is_numeric($home['beds'] ?? null) ? $home['beds'].' bed' : null,
            is_numeric($home['baths_full'] ?? null) ? ((int) $home['baths_full'] + (is_numeric($home['baths_half'] ?? null) && (int) $home['baths_half'] > 0 ? 0.5 : 0)).' bath' : null,
            is_numeric($home['sqft'] ?? null) ? number_format((int) $home['sqft']).' sq ft' : null]);

        return ['listing' => $ulid, 'text' => (($home['status'] ?? '') === 'Closed' ? 'Just sold: ' : 'Just listed: ').$label.($facts !== [] ? "\n".implode(' · ', $facts) : ''),
            'link' => $link, 'image' => $this->jpeg($ulid)];
    }

    /** Most images a carousel carries (Instagram's limit; Facebook and LinkedIn take as many). */
    public const MAX_IMAGES = 10;

    /**
     * @param  array<string, mixed>  $in  text, link, image_url, images (a carousel, in order), video_url (a Reel, with image_url as its cover), listing, places (keys), post_at (local "Y-m-d\TH:i", empty for now)
     */
    public function schedule(User $by, array $in): SocialPost
    {
        $text = trim(strip_tags((string) ($in['text'] ?? '')));
        $link = trim((string) ($in['link'] ?? '')) ?: null;
        $image = trim((string) ($in['image_url'] ?? '')) ?: null;
        $images = array_values(array_filter(array_map(fn ($u): string => trim((string) $u), (array) ($in['images'] ?? [])), fn (string $u): bool => $u !== ''));
        $image ??= $images[0] ?? null;
        $video = trim((string) ($in['video_url'] ?? '')) ?: null;
        $listing = is_string($in['listing'] ?? null) && $in['listing'] !== '' ? $in['listing'] : null;
        $home = null;
        if ($listing !== null) {
            $start = $this->fromListing($by, $listing);
            $home = $this->ads->listing($by, $listing);
            $link ??= $start['link'];
            $image ??= $start['image'];
        }
        $all = collect($this->places($by))->keyBy('key');
        $chosen = $all->only(array_map('strval', (array) ($in['places'] ?? [])))->values();
        $channels = $chosen->pluck('channel')->unique()->all();
        $tz = $this->tenants->current()->timezone ?: 'America/New_York';
        $when = null;
        try {
            $when = trim((string) ($in['post_at'] ?? '')) === '' ? CarbonImmutable::now() : CarbonImmutable::parse((string) $in['post_at'], $tz)->utc();
        } catch (Throwable) {
        }
        $errors = array_filter([
            'text' => $text === '' ? 'Write the post.' : (mb_strlen($text) > self::MAX_TEXT ? 'Keep the post under '.number_format(self::MAX_TEXT).' characters.'
                : (in_array('google', $channels, true) && mb_strlen($text) > 1_300 ? 'Google allows 1,500 characters including your name: shorten the post or leave Google out.' : null)),
            'link' => $link !== null && (! str_starts_with($link, 'https://') || filter_var($link, FILTER_VALIDATE_URL) === false) ? 'The link must be a full https:// web address.' : null,
            'image_url' => $image !== null && (! str_starts_with($image, 'https://') || filter_var($image, FILTER_VALIDATE_URL) === false) ? 'The photo must be a full https:// web address.'
                : (in_array('instagram', $channels, true) && $video === null && ($image === null || preg_match('/\.jpe?g$/i', (string) parse_url($image, PHP_URL_PATH)) !== 1)
                    ? 'Instagram needs a photo in JPEG format (.jpg): start from a listing or add one.' : null),
            'video_url' => $video !== null && (! str_starts_with($video, 'https://') || preg_match('/\.mp4$/i', (string) parse_url($video, PHP_URL_PATH)) !== 1)
                ? 'The video must be an https:// MP4 address.'
                : ($video === null && in_array('youtube', $channels, true) ? 'YouTube takes videos: start from a Reel or a listing tour, or leave YouTube out.'
                    : ($video !== null && str_contains((string) parse_url($video, PHP_URL_PATH), '/tours/') && in_array('instagram', $channels, true)
                        ? 'Listing tours are landscape: post them to YouTube and Facebook, and Reels to Instagram.' : null)),
            'images' => count($images) > self::MAX_IMAGES ? 'A carousel can have up to '.self::MAX_IMAGES.' images.'
                : (array_filter($images, fn (string $u): bool => ! str_starts_with($u, 'https://') || preg_match('/\.jpe?g$/i', (string) parse_url($u, PHP_URL_PATH)) !== 1) !== []
                    ? 'Carousel images must be https:// JPEG (.jpg) addresses.' : null),
            'places' => $chosen->isEmpty() ? ($all->isEmpty() ? 'Connect Facebook Pages, LinkedIn or Google Business Profile under Apps first.' : 'Choose where to post.') : null,
            'post_at' => $when === null || $when->lt(CarbonImmutable::now()->subMinutes(2)) || $when->gt(CarbonImmutable::now()->addDays(60)) ? 'Choose a time in the next 60 days.' : null,
        ]);
        if ($text !== '' && ($flags = FairHousing::screen($text, 'ads', 'blocked', 'Social post')) !== []) {
            $errors['text'] = 'Fair Housing: please remove "'.implode('", "', $flags).'". Describe the home and the area, not who should live there.';
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
        $post = DB::connection(Connections::cell())->transaction(function () use ($by, $listing, $text, $home, $link, $image, $images, $video, $when, $chosen): SocialPost {
            $post = SocialPost::query()->create(['user_id' => $by->id, 'listing_ulid' => $listing, 'text' => $this->sign($text, $by, $home), 'link' => $link, 'image_url' => $image,
                'images' => count($images) >= 2 && $video === null ? $images : null, 'video_url' => $video,
                'status' => 'scheduled', 'post_at' => $when]);
            foreach ($chosen as $p) {
                SocialTarget::query()->create(['post_id' => $post->id, 'connection_id' => $p['connection_id'], 'channel' => $p['channel'], 'target_id' => $p['id'],
                    'name' => mb_substr($p['name'], 0, 120), 'status' => 'waiting']);
            }

            return $post;
        });
        app(AuditLogger::class)->record('ads.social.scheduled', $post, metadata: ['places' => $chosen->count(), 'listing' => $listing, 'at' => $post->post_at->toIso8601String()],
            label: mb_substr($text, 0, 80));
        if ($post->post_at->lte(CarbonImmutable::now())) {
            PublishSocialPost::dispatch($post->id);
        }

        return $post;
    }

    /** Cancel a scheduled post before it goes out. */
    public function cancel(SocialPost $post, User $by): void
    {
        abort_unless($post->user_id === $by->id, 404);
        if (SocialPost::query()->whereKey($post->id)->where('status', 'scheduled')->update(['status' => 'cancelled']) === 0) {
            throw ValidationException::withMessages(['post' => 'This post has already gone out.']);
        }
        app(AuditLogger::class)->record('ads.social.cancelled', $post, label: mb_substr($post->text, 0, 80));
    }

    /** Every minute: send the posts that are due. */
    public function runDue(): int
    {
        $sent = 0;
        foreach (SocialPost::query()->where('status', 'scheduled')->where('post_at', '<=', CarbonImmutable::now())->orderBy('id')->limit(50)->get() as $post) {
            $sent += $this->publish($post) !== null ? 1 : 0;
        }

        return $sent;
    }

    /** Send one post to each of its places (once: a second run finds nothing waiting). */
    public function publish(SocialPost $post): ?SocialPost
    {
        if (SocialPost::query()->whereKey($post->id)->where('status', 'scheduled')->update(['status' => 'posting']) === 0) {
            return null;
        }
        foreach (SocialTarget::query()->where('post_id', $post->id)->where('status', 'waiting')->orderBy('id')->get() as $t) {
            $c = $this->kit->connection($t->connection_id);
            $driver = $c !== null ? (self::APPS[$c->appKey] ?? null) : null;
            try {
                if ($c === null || $driver === null) {
                    throw new PostFailed('The app was disconnected.');
                }
                $done = app($driver)->post($c, $t->channel, $t->target_id, $this->content($post, $t->channel));
                $t->forceFill(['status' => 'posted', 'external_id' => mb_substr($done['id'], 0, 191), 'url' => $done['url'] !== null ? mb_substr($done['url'], 0, 500) : null,
                    'error' => null, 'done_at' => CarbonImmutable::now()])->save();
            } catch (PostFailed|NeedsReconnect $e) {
                $t->forceFill(['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 300), 'done_at' => CarbonImmutable::now()])->save();
            } catch (Throwable $e) {
                report($e);
                $t->forceFill(['status' => 'failed', 'error' => 'Something went wrong while posting. RightAlly support has been told.', 'done_at' => CarbonImmutable::now()])->save();
            }
        }
        $statuses = SocialTarget::query()->where('post_id', $post->id)->pluck('status')->all();
        $posted = count(array_filter($statuses, fn (string $s): bool => $s === 'posted'));
        $post->forceFill(['status' => $posted === count($statuses) ? 'posted' : ($posted === 0 ? 'failed' : 'partial'), 'posted_at' => CarbonImmutable::now()])->save();
        app(AuditLogger::class)->record('ads.social.posted', $post, metadata: ['posted' => $posted, 'failed' => count($statuses) - $posted], label: mb_substr($post->text, 0, 80));

        return $post;
    }

    private function mayUse(?int $owner, User $user): bool
    {
        return $owner === $user->id || ($owner === null && in_array($user->kind, [UserKind::Owner, UserKind::Staff], true));
    }

    /**
     * The words people see: the text, then (unless it already names the brokerage) the agent and brokerage, and a
     * listing's MLS attribution.
     *
     * @param  array<string, mixed>|null  $home
     */
    private function sign(string $text, User $by, ?array $home): string
    {
        $brokerage = (string) $this->tenants->current()->name;
        $out = $text;
        if ($brokerage !== '' && mb_stripos($text, $brokerage) === false) {
            $out .= "\n\n".trim($by->first_name.' '.$by->last_name).', '.$brokerage;
        }
        $attribution = is_string($home['attribution'] ?? null) ? trim($home['attribution']) : '';
        if ($attribution !== '' && mb_stripos($out, $attribution) === false) {
            $out .= "\n".$attribution;
        }

        return $out;
    }

    /** @return array{text: string, link: string|null, image: string|null, images: list<string>, video: string|null, title: string} */
    private function content(SocialPost $post, string $channel): array
    {
        $link = $post->link !== null ? $post->link.(str_contains($post->link, '?') ? '&' : '?').http_build_query(['utm_source' => $channel, 'utm_medium' => 'social',
            'utm_campaign' => 'rightally-'.strtolower($post->ulid)]) : null;

        return ['text' => $post->text, 'link' => $link, 'image' => $post->image_url, 'images' => $post->images ?? [], 'video' => $post->video_url,
            'title' => mb_substr(strtok($post->text, "\n") ?: 'Read more', 0, 100)];
    }

    /** The listing's cover photo as a JPEG on the media disk (listing photos are stored as WebP). */
    private function jpeg(string $ulid): ?string
    {
        $cover = Listing::query()->where('ulid', $ulid)->value('cover_path');
        if (! is_string($cover) || $cover === '' || ! function_exists('imagecreatefromstring')) {
            return null;
        }
        $disk = Storage::disk((string) config('listings.media_disk', 'public'));
        $out = 'social/'.sha1($cover).'.jpg';
        if (! $disk->exists($out)) {
            $webp = $disk->get($cover.'-'.(int) (config('listings.sizes')['hero'] ?? 1600).'.webp');
            $img = is_string($webp) && $webp !== '' ? @imagecreatefromstring($webp) : false;
            if ($img === false) {
                return null;
            }
            ob_start();
            imagejpeg($img, null, 85);
            $disk->put($out, (string) ob_get_clean(), ['visibility' => 'public', 'CacheControl' => 'public, max-age=31536000, immutable']);
        }
        $cdn = config('listings.cdn_url');

        return is_string($cdn) && $cdn !== '' ? rtrim($cdn, '/').'/'.$out : $disk->url($out);
    }
}
