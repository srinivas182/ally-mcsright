<?php

declare(strict_types=1);

namespace Modules\Ads\Http;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Ads\Studio\StudioPlans;
use Modules\Ads\Studio\StudioVideo;
use Modules\Ads\Studio\Voice\Narration;
use Modules\Connect\AppRegistry;
use Modules\Core\Connect\AppCatalog;
use Modules\Core\Identity\Enums\UserKind;

/**
 * Studio → My voice, and narrating Reels and tours (specs/grow/13, SA5b). An agent's own voice is only ever made
 * by that agent, for themselves, with their signed and spoken consent; they can delete it at any time.
 */
final class VoiceController
{
    public function __construct(private readonly Narration $narration) {}

    public function show(Request $request, StudioPlans $plans): Response
    {
        $user = self::user($request);
        $own = $this->narration->ownVoice($user);
        $app = app(AppCatalog::class)->find('studio_voice');

        return Inertia::render('studio/voice', [
            'ready' => $plans->current($user) !== null,
            'on' => $app !== null && app(AppRegistry::class)->ready($app),
            'name' => trim($user->first_name.' '.$user->last_name),
            'consent' => $this->narration->consentText($user),
            'sample' => Narration::SAMPLE,
            'own' => $own === null ? null : ['since' => $own->consent_at->toDateString(), 'last_used' => $own->last_used_at?->toDateString()],
            'usage' => $plans->usage($user)['voice'],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $user = self::user($request);
        $files = $request->file('recordings');
        $list = $files instanceof UploadedFile ? [$files] : array_values(is_array($files) ? $files : []);
        $this->narration->consent($user, (string) $request->input('name', ''), $request->boolean('agree'), $list, $request->ip());
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Your voice is ready. Choose "My own voice" when you narrate a Reel or tour.']);

        return redirect('/studio/voice');
    }

    public function destroy(Request $request): RedirectResponse
    {
        $user = self::user($request);
        $own = $this->narration->ownVoice($user);
        if ($own !== null) {
            $this->narration->withdraw($own, 'Deleted by the agent');
        }
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Your voice is deleted, here and at the voice service.']);

        return redirect('/studio/voice');
    }

    /** An AI first draft of the voice-over for one of the person's videos. */
    public function script(Request $request, string $reel): JsonResponse
    {
        $user = self::user($request);
        $v = StudioVideo::query()->where('ulid', $reel)->where('user_id', $user->id)->firstOrFail();

        return response()->json(['script' => $this->narration->draft($user, $v), 'max_words' => Narration::maxWords($v)]);
    }

    public function narrate(Request $request, string $reel): RedirectResponse
    {
        $user = self::user($request);
        $v = StudioVideo::query()->where('ulid', $reel)->where('user_id', $user->id)->firstOrFail();
        $this->narration->narrate($user, $v, (string) $request->input('narration', ''), (string) $request->input('voice', ''));
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Adding the voice-over. It takes about a minute.']);

        return redirect('/studio/reels');
    }

    private static function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User && in_array($user->kind, [UserKind::Owner, UserKind::Staff, UserKind::Agent], true), 403);

        return $user;
    }
}
