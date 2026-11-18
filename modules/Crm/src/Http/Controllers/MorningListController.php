<?php

declare(strict_types=1);

namespace Modules\Crm\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Inertia\Inertia;
use Modules\Core\Identity\Enums\UserStatus;
use Modules\Core\Simple\HomePeople;
use Modules\Crm\Actions\Timeline;
use Modules\Crm\Brief\MorningList;
use Modules\Crm\ContactVisibility;
use Modules\Crm\Models\Contact;

/** SX6: "your list for today" without signing in (signed link from the morning email or text), and the text choice. */
final class MorningListController
{
    public function show(Request $request, string $user, HomePeople $people): Response
    {
        $agent = self::agent($user);

        return response()->view('crm::morning-list', ['name' => $agent->first_name, 'people' => $people->for($agent), 'outcomes' => MorningList::OUTCOMES,
            'done' => $request->session()->get('logged')]);
    }

    /** A "how did it go?" button on that page: logs the call for that agent. */
    public function log(Request $request, string $user, Timeline $timeline, ContactVisibility $visibility): RedirectResponse
    {
        $agent = self::agent($user);
        $data = $request->validate(['contact' => ['required', 'string', 'size:26'], 'outcome' => ['required', 'in:'.implode(',', array_keys(MorningList::OUTCOMES))]]);
        $contact = $visibility->query($agent)->where('ulid', $data['contact'])->firstOrFail();
        /** @var Contact $contact */
        $timeline->log($contact, ['type' => 'call', 'direction' => 'out', 'outcome' => $data['outcome'], 'body' => 'Logged from the morning list'], $agent);

        return redirect()->to($request->fullUrl())->with('logged', $contact->name.': '.MorningList::OUTCOMES[$data['outcome']]);
    }

    public function text(Request $request, MorningList $list): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);
        $on = $request->boolean('on');
        $list->setText($user, $on);
        Inertia::flash('toast', ['type' => 'success', 'message' => $on ? 'Each weekday morning you\'ll get a text with your list (reply STOP to stop).' : 'Morning text turned off.']);

        return back();
    }

    private static function agent(string $ulid): User
    {
        return User::query()->where('ulid', $ulid)->where('status', UserStatus::Active)->firstOrFail();
    }
}
