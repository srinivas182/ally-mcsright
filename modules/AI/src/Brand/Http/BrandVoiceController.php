<?php

declare(strict_types=1);

namespace Modules\AI\Brand\Http;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\AI\Brand\BrandGuide;
use Modules\AI\Brand\BrandVoice;
use Modules\Core\Access\Permissions;
use Modules\Core\Branding\BrandGuideApproved;
use Modules\Core\Identity\Enums\UserKind;
use Modules\Core\Tenancy\TenantContext;

/** Settings → Brand voice (BV1, specs/core/08): upload the guide, review what the AI read, approve; the consistency report. */
final class BrandVoiceController
{
    public function show(Request $request, BrandVoice $voice): Response
    {
        $this->owner($request);
        $row = $voice->row();
        $words = fn (mixed $json): array => array_values(array_map('strval', (array) json_decode((string) $json, true)));

        return Inertia::render('settings/brand-voice', [
            'voice' => $row !== null ? ['tone' => (string) $row->tone, 'use_words' => $words($row->use_words), 'avoid_words' => $words($row->avoid_words),
                'emojis' => (bool) $row->emojis, 'sign_off' => $row->sign_off, 'spanish' => (string) $row->spanish, 'name_brokerage' => (bool) $row->name_brokerage,
                'approved_at' => $row->approved_at] : null,
            'guide' => $row !== null && $row->guide !== null ? (array) json_decode((string) $row->guide, true) + ['file' => $row->guide_file] : null,
            'tones' => BrandVoice::TONES,
            'report' => $voice->report(),
            'brokerage' => (string) app(TenantContext::class)->current()->name,
        ]);
    }

    public function upload(Request $request, BrandGuide $guide): RedirectResponse
    {
        $user = $this->owner($request);
        $request->validate(['guide' => ['required', 'file', 'mimetypes:application/pdf', 'max:'.(BrandGuide::MAX_MB * 1024)]]);
        $guide->read($request->file('guide'), $user);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Read. Check what the AI found, change anything, then approve.']);

        return back();
    }

    public function save(Request $request, BrandVoice $voice): RedirectResponse
    {
        $user = $this->owner($request);
        $data = $request->validate(['tone' => ['required', 'string'], 'use_words' => ['nullable', 'array', 'max:40'], 'use_words.*' => ['string', 'max:40'],
            'avoid_words' => ['nullable', 'array', 'max:40'], 'avoid_words.*' => ['string', 'max:40'], 'emojis' => ['required', 'boolean'], 'sign_off' => ['nullable', 'string', 'max:120'],
            'spanish' => ['required', 'in:usted,tu'], 'name_brokerage' => ['required', 'boolean'], 'apply_kit' => ['nullable', 'boolean'],
            'colors' => ['nullable', 'array', 'max:6'], 'colors.*' => ['string', 'regex:/^#[0-9A-Fa-f]{6}$/'], 'fonts' => ['nullable', 'array', 'max:3'], 'fonts.*' => ['string', 'max:60'],
            'disclaimer' => ['nullable', 'string', 'max:300']]);
        $voice->save($data, $user);
        if (($data['apply_kit'] ?? false) === true) {
            BrandGuideApproved::dispatch(app(TenantContext::class)->id(), array_values($data['colors'] ?? []), array_values($data['fonts'] ?? []), $data['disclaimer'] ?? null, $user->id);
        }
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Approved. All AI writing now follows your brand voice.']);

        return back();
    }

    private function owner(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User && ($user->kind === UserKind::Owner || app(Permissions::class)->allows($user, 'marketing.manage')), 403);

        return $user;
    }
}
