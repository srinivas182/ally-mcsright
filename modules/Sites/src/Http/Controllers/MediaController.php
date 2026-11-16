<?php

declare(strict_types=1);

namespace Modules\Sites\Http\Controllers;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Sites\Content\MediaLibrary;
use Modules\Sites\Models\Site;
use Modules\Sites\Models\SiteMedia;
use Modules\Sites\SiteAccess;
use Modules\Sites\SiteSetup;

/** Settings → Website → media library (specs/websites/08). */
final class MediaController
{
    public function __construct(private readonly SiteSetup $sites, private readonly MediaLibrary $media) {}

    public function index(Request $request): Response
    {
        self::manager($request);
        $site = $this->site();

        return Inertia::render('settings/website-media', [
            'media' => SiteMedia::query()->where('site_id', $site->id)->latest('id')->limit(500)->get()->map(fn (SiteMedia $m): array => [
                'ulid' => $m->ulid, 'thumb' => MediaLibrary::urlFor($m, 'thumb'), 'url' => MediaLibrary::urlFor($m, 'hero'), 'alt' => $m->alt, 'name' => $m->original_name,
                'width' => $m->width, 'height' => $m->height, 'kb' => (int) ceil($m->bytes / 1024), 'used' => $this->media->usage($m), 'uploaded' => $m->created_at?->toIso8601String()]),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);
        $data = $request->validate(['file' => ['required', 'file', 'max:10240'], 'alt' => ['required', 'string', 'max:250'], 'site' => ['nullable', 'string', 'size:26']]);
        // Agents upload to their own website's library; owners and staff to the brokerage's (or any of its websites).
        $site = isset($data['site']) ? Site::query()->where('ulid', $data['site'])->first() : $this->site();
        abort_unless($site !== null && SiteAccess::canEdit($user, $site), 403);
        $m = $this->media->store($site, $data['file'], $data['alt'], $user);

        return response()->json(['media' => ['ulid' => $m->ulid, 'url' => MediaLibrary::urlFor($m, 'thumb'), 'alt' => $m->alt]], 201);
    }

    public function update(Request $request, SiteMedia $media): JsonResponse
    {
        self::manager($request);
        abort_unless($media->site_id === $this->site()->id, 404);
        $media->forceFill(['alt' => mb_substr(trim(strip_tags((string) $request->validate(['alt' => ['required', 'string', 'max:250']])['alt'])), 0, 250)])->save();

        return response()->json(['ok' => true]);
    }

    public function destroy(Request $request, SiteMedia $media): JsonResponse
    {
        self::manager($request);
        abort_unless($media->site_id === $this->site()->id, 404);
        $this->media->delete($media);

        return response()->json(['ok' => true]);
    }

    private function site(): Site
    {
        return $this->sites->brokerageSite() ?? abort(404);
    }

    private static function manager(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User && SiteAccess::manager($user), 403);

        return $user;
    }
}
