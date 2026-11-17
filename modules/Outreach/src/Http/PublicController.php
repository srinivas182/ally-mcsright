<?php

declare(strict_types=1);

namespace Modules\Outreach\Http;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Modules\Core\Tenancy\TenantContext;
use Modules\Outreach\BookingLinks;
use Modules\Outreach\Videos;
use Modules\Sites\Http\Controllers\SiteController;
use Modules\Sites\Models\Site;
use Modules\Sites\Rendering\Chrome;
use Modules\Sites\Rendering\Seo;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/** /meet/{name} booking pages and /v/{token} video pages on the brokerage's website (MK1, specs/crm/20). */
final class PublicController
{
    private const PRIVATE = ['Cache-Control' => 'private, no-store', 'X-Robots-Tag' => 'noindex, nofollow', 'Referrer-Policy' => 'no-referrer'];

    public function meet(Request $request, BookingLinks $links, string $slug): Response
    {
        $site = self::site();
        $link = $site !== null ? $links->bySlug($slug) : null;
        if ($site === null || $link === null) {
            return response()->view('sites::missing', ['site' => $site], 404)->withHeaders(self::PRIVATE);
        }
        $booked = is_string($request->query('booked')) ? $request->query('booked') : null;

        return response()->view('outreach::meet', [
            'site' => $site, 'current' => '/meet/'.$slug, 'link' => $link, 'agent' => $link['user']->name, 'kind' => BookingLinks::KINDS[$link['kind']] ?? 'Call',
            'days' => $booked === null ? $links->slots($link['user'], $link['kind']) : [], 'booked' => $booked,
            'seo' => Seo::build($site, $request->getSchemeAndHttpHost(), '/meet/'.$slug, $link['title'].' | '.$site->displayName(), 'Choose a time that suits you.', null, false),
            'chrome' => Chrome::for($site, app(TenantContext::class)->id()),
            ...SiteController::form($request),
        ])->withHeaders(self::PRIVATE);
    }

    public function book(Request $request, BookingLinks $links, string $slug): RedirectResponse
    {
        $back = $request->getSchemeAndHttpHost().'/meet/'.$slug;
        $site = self::site();
        if ($site === null || trim((string) $request->input('website', '')) !== '') {
            return redirect($back, 303);
        }
        try {
            $shown = (int) Crypt::decryptString((string) $request->input('t', ''));
        } catch (\Throwable) {
            $shown = PHP_INT_MAX;
        }
        if (time() - $shown < 3 || ! RateLimiter::attempt('meet-book:'.$request->ip(), 6, fn (): bool => true, 3600)) {
            return redirect($back.'?error='.urlencode('Please try again in a moment.'), 303);
        }
        try {
            $at = $links->book($site, $slug, (string) $request->input('slot', ''), ['name' => mb_substr(trim((string) $request->input('name', '')), 0, 120),
                'email' => mb_substr(trim((string) $request->input('email', '')), 0, 150), 'phone' => mb_substr(trim((string) $request->input('phone', '')), 0, 30),
                'notes' => mb_substr(trim((string) $request->input('notes', '')), 0, 1000), 'sms_consent' => $request->boolean('sms_consent')], $request);
        } catch (ValidationException $e) {
            return redirect($back.'?error='.urlencode((string) (collect($e->errors())->flatten()->first() ?? 'Please check the form.')).'#book', 303);
        }

        return redirect($back.'?booked='.urlencode($at->format('l, F j \a\t g:i A')), 303);
    }

    public function video(Request $request, Videos $videos, string $token): Response
    {
        $site = self::site();
        $video = $site !== null ? $videos->byToken($token) : null;
        if ($site === null || $video === null) {
            return response()->view('sites::missing', ['site' => $site], 404)->withHeaders(self::PRIVATE);
        }
        $videos->viewed($video);
        $agent = User::query()->whereKey((int) $video->user_id)->first();

        return response()->view('outreach::video', [
            'site' => $site, 'current' => '/v', 'video' => $video, 'agent' => $agent !== null ? $agent->name : $site->displayName(), 'phone' => $agent?->phone, 'email' => $agent?->email,
            'seo' => Seo::build($site, $request->getSchemeAndHttpHost(), '/v/'.$token, $video->title.' | '.$site->displayName(), 'A video message for you.', null, false),
            'chrome' => Chrome::for($site, app(TenantContext::class)->id()),
        ])->withHeaders(self::PRIVATE);
    }

    public function videoFile(Videos $videos, string $token): BinaryFileResponse|Response
    {
        $video = self::site() !== null ? $videos->byToken($token) : null;
        if ($video === null || ! Storage::disk('local')->exists((string) $video->path)) {
            return response('', 404)->withHeaders(self::PRIVATE);
        }

        return response()->file(Storage::disk('local')->path((string) $video->path), ['Content-Type' => (string) $video->mime] + self::PRIVATE);
    }

    private static function site(): ?Site
    {
        return Site::query()->where('kind', 'brokerage')->where('status', 'live')->first();
    }
}
