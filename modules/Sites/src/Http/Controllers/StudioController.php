<?php

declare(strict_types=1);

namespace Modules\Sites\Http\Controllers;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Modules\AI\Exceptions\AiOutputBlocked;
use Modules\AI\Exceptions\InsufficientCredits;
use Modules\AI\Exceptions\UnknownFeature;
use Modules\Crm\CrmAccess;
use Modules\Sites\Ai\ContentStudio;
use Modules\Sites\Ai\MarketUpdates;
use Modules\Sites\Models\Site;
use Modules\Sites\SiteSetup;

/** Settings → Website → AI content studio (specs/websites/10). Drafts only; nothing is published by AI. */
final class StudioController
{
    public function __construct(private readonly ContentStudio $studio, private readonly SiteSetup $sites) {}

    public function post(Request $request): RedirectResponse
    {
        $user = self::user($request);
        abort_unless(CrmAccess::manager($user), 403);
        $brief = $request->validate(self::briefRules() + ['length' => ['nullable', 'in:short,medium,long'], 'category' => ['nullable', 'string', 'max:60']]);
        $r = self::safely(fn (): array => $this->studio->writePost($this->brokerage(), $brief, $user));
        $request->session()->flash('ai_notes', ['score' => $r['score'], 'warnings' => $r['warnings']]);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Draft written (score '.$r['score'].'). Review it, then publish.']);

        return redirect('/settings/website/blog/'.$r['post']->ulid);
    }

    public function page(Request $request): RedirectResponse
    {
        $user = self::user($request);
        $brief = $request->validate(self::briefRules() + ['path' => ['nullable', 'string', 'max:130']]);
        $r = self::safely(fn (): array => $this->studio->writePage($this->brokerage(), $brief, $user));
        $request->session()->flash('ai_notes', ['score' => $r['score'], 'warnings' => $r['warnings']]);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Page drafted (score '.$r['score'].'). Review it, then publish.']);

        return redirect('/settings/website/pages/'.$r['page']->ulid.'/edit');
    }

    public function assist(Request $request): JsonResponse
    {
        $user = self::user($request);
        $data = $request->validate(['action' => ['required', 'in:rewrite,faq,meta,bio'], 'mode' => ['nullable', 'in:improve,shorter,longer'], 'content' => ['nullable', 'string', 'max:20000'],
            'title' => ['nullable', 'string', 'max:200'], 'profile' => ['nullable', 'array'], 'site' => ['nullable', 'string', 'size:26']]);
        $site = isset($data['site']) ? (Site::query()->where('ulid', $data['site'])->first() ?? abort(404)) : $this->brokerage();

        return response()->json(self::safely(fn (): array => $this->studio->assist($site, $data['action'], $data, $user)));
    }

    public function marketUpdates(Request $request, MarketUpdates $updates): RedirectResponse
    {
        $data = $request->validate(['enabled' => ['required', 'boolean'], 'places' => ['nullable', 'array', 'max:10'], 'places.*' => ['string', 'max:80']]);
        $updates->save((bool) $data['enabled'], array_values($data['places'] ?? []), self::user($request));
        Inertia::flash('toast', ['type' => 'success', 'message' => $data['enabled'] ? 'Monthly market updates are on. Drafts arrive on the 1st.' : 'Monthly market updates are off.']);

        return back();
    }

    /**
     * @return array<string, list<string>>
     */
    private static function briefRules(): array
    {
        return ['topic' => ['required', 'string', 'max:200'], 'audience' => ['nullable', 'string', 'max:120'], 'tone' => ['nullable', 'in:friendly,expert,luxury'],
            'area' => ['nullable', 'string', 'max:80'], 'keywords' => ['nullable', 'string', 'max:300']];
    }

    /**
     * Turns AI gateway problems into messages people can act on.
     *
     * @template T
     *
     * @param  callable(): T  $work
     * @return T
     */
    private static function safely(callable $work): mixed
    {
        try {
            return $work();
        } catch (UnknownFeature) {
            throw ValidationException::withMessages(['ai' => 'AI writing is not set up for your account yet. Ask RightAlly support to turn it on.']);
        } catch (InsufficientCredits $e) {
            throw ValidationException::withMessages(['ai' => $e->getMessage() ?: 'Not enough AI credits. Top up to keep writing.']);
        } catch (AiOutputBlocked) {
            throw ValidationException::withMessages(['ai' => 'The draft included details we do not publish (such as personal contact details). Try a different brief.']);
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            report($e);
            throw ValidationException::withMessages(['ai' => 'The AI writer is busy right now. Try again in a minute.']);
        }
    }

    private function brokerage(): Site
    {
        return $this->sites->brokerageSite() ?? abort(404);
    }

    private static function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
