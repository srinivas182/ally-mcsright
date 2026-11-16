<?php

declare(strict_types=1);

namespace Modules\Ads\Http;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Ads\Studio\Canva\CanvaSession;
use Modules\Ads\Studio\Canva\CanvaStudio;
use Modules\Ads\Studio\Design;
use Modules\Ads\Studio\Designer;
use Modules\Core\Identity\Enums\UserKind;

/** Studio → Canva (specs/grow/13, C7d-3): open a design in Canva and bring the finished one back. */
final class CanvaController
{
    public function __construct(private readonly CanvaStudio $canva) {}

    public function start(Request $request): RedirectResponse
    {
        $s = $this->canva->start(self::user($request), $request->string('listing')->toString(), $request->string('design')->toString(), $request->getHost());

        return redirect('/studio/canva/'.$s->ulid);
    }

    public function show(Request $request, string $session, Designer $designer): Response
    {
        $user = self::user($request);
        $s = CanvaSession::query()->where('ulid', $session)->where('user_id', $user->id)->firstOrFail();
        $d = $s->result_design_id !== null ? Design::query()->find($s->result_design_id) : null;

        return Inertia::render('studio/canva', [
            'session' => ['ulid' => $s->ulid, 'status' => $s->status, 'error' => $s->error, 'edit_url' => $s->status === 'editing' ? $s->edit_url : null],
            'design' => $d === null ? null : ['ulid' => $d->ulid, 'listing' => $d->listing_ulid, 'image' => $designer->url($d, 'portrait'), 'slides' => count($designer->carousel($d))],
        ]);
    }

    /** Back from Canva (via the central return address). */
    public function back(Request $request, string $session): RedirectResponse
    {
        $user = self::user($request);
        $s = CanvaSession::query()->where('ulid', $session)->where('user_id', $user->id)->firstOrFail();
        $this->canva->back($s, $user);

        return redirect('/studio/canva/'.$s->ulid);
    }

    private static function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User && in_array($user->kind, [UserKind::Owner, UserKind::Staff, UserKind::Agent], true), 403);

        return $user;
    }
}
