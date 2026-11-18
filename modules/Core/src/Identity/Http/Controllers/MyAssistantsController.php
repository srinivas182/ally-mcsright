<?php

declare(strict_types=1);

namespace Modules\Core\Identity\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Core\Identity\AgentAssistants;
use Modules\Core\Identity\Enums\UserKind;

/** My assistants (AG1, specs/grow/03): an agent adds, changes and removes their own assistants. */
final class MyAssistantsController
{
    public function index(Request $request, AgentAssistants $assistants): Response
    {
        $agent = $this->agent($request);

        return Inertia::render('settings/my-assistants', ['assistants' => $assistants->of($agent), 'waiting' => $assistants->waiting($agent),
            'presets' => AgentAssistants::PRESETS, 'max' => AgentAssistants::MAX]);
    }

    public function invite(Request $request, AgentAssistants $assistants): RedirectResponse
    {
        $data = $request->validate(['email' => ['required', 'email:rfc', 'max:254'], 'first_name' => ['required', 'string', 'max:80'], 'last_name' => ['required', 'string', 'max:80'],
            'preset' => ['required', 'string', 'max:10']]);
        $r = $assistants->invite($this->agent($request), $data['email'], $data['first_name'], $data['last_name'], $data['preset']);
        Inertia::flash('toast', ['type' => 'success', 'message' => $r['message']]);

        return back();
    }

    public function update(Request $request, AgentAssistants $assistants, string $assistant): RedirectResponse
    {
        $assistants->setPreset($this->agent($request), $this->find($assistant), (string) $request->validate(['preset' => ['required', 'string', 'max:10']])['preset']);

        return back();
    }

    public function remove(Request $request, AgentAssistants $assistants, string $assistant): RedirectResponse
    {
        $assistants->remove($this->agent($request), $this->find($assistant));
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Removed. They can no longer sign in.']);

        return back();
    }

    private function find(string $ulid): User
    {
        return User::query()->where('ulid', $ulid)->firstOrFail();
    }

    private function agent(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User && $user->kind === UserKind::Agent, 403);

        return $user;
    }
}
