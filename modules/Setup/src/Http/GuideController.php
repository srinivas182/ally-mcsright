<?php

declare(strict_types=1);

namespace Modules\Setup\Http;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Core\Identity\Enums\UserKind;
use Modules\Setup\Guide\Guide;

/** SU2 (specs/setup/02): the guided setup and how ready the brokerage is to go live. Owners only. */
final class GuideController
{
    public function show(Request $request, Guide $guide): Response
    {
        self::owner($request);
        $state = $guide->state();

        return Inertia::render('setup/guide', [
            'answers' => (object) array_merge($state['answers'], (array) $request->session()->get('setupSuggested', [])),
            'suggested' => $request->session()->has('setupSuggested'),
            'unclear' => (array) $request->session()->get('setupUnclear', []),
            'applied' => (object) $state['applied'],
            'readiness' => $guide->readiness(),
            'routing' => Guide::ROUTING,
        ]);
    }

    public function describe(Request $request, Guide $guide): RedirectResponse
    {
        $user = self::owner($request);
        $data = $request->validate(['words' => ['required', 'string', 'max:4000']]);
        $read = $guide->describe($data['words'], $user);

        return back()->with('setupSuggested', $read['answers'])->with('setupUnclear', $read['unclear']);
    }

    public function apply(Request $request, Guide $guide): RedirectResponse
    {
        $user = self::owner($request);
        $a = $request->validate([
            'pay_mode' => ['nullable', 'in:split,flat'],
            'agent_percent' => ['nullable', 'required_if:pay_mode,split', 'numeric', 'min:0', 'max:100'],
            'cap_dollars' => ['nullable', 'numeric', 'min:0', 'max:1000000'],
            'fee_dollars' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'flat_fee_dollars' => ['nullable', 'required_if:pay_mode,flat', 'numeric', 'min:0', 'max:100000'],
            'lead_routing' => ['nullable', 'in:round_robin,first_claim,owner'],
            'ai_first_reply' => ['nullable', 'boolean'],
            'website_style' => ['nullable', 'in:light,dark,warm'],
        ]);
        $guide->apply(array_filter($a, fn ($v): bool => $v !== null), $user);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Done. Here is what was set up.']);

        return back();
    }

    private static function owner(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User && $user->kind === UserKind::Owner, 403);

        return $user;
    }
}
