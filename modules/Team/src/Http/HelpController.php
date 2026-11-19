<?php

declare(strict_types=1);

namespace Modules\Team\Http;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Core\Support\Connections;
use Modules\Crm\Practice\PracticeClients;
use Modules\Team\Chat;
use Modules\Team\Help\Concierge;
use Modules\Team\Help\HelpCentre;
use Modules\Team\Help\PageHelp;
use Modules\Team\Help\SupportTickets;

/** The help centre and support requests (CH1, specs/team/01). */
final class HelpController
{
    public function index(Request $request, HelpCentre $help, SupportTickets $tickets): Response
    {
        $user = self::user($request);
        $leader = Chat::leads($user);
        $q = trim((string) $request->query('q', ''));
        $sections = [];
        foreach ($help->visible($leader) as $a) {
            $sections[$a['section']][] = ['slug' => $a['slug'], 'title' => $a['title'], 'summary' => $a['summary']];
        }

        return Inertia::render('team/help', ['q' => $q, 'results' => $q !== '' ? $help->search($q, $leader) : null, 'sections' => $sections,
            'requests' => $tickets->mine($user), 'topics' => SupportTickets::TOPICS]);
    }

    public function article(Request $request, string $slug, HelpCentre $help): Response
    {
        $user = self::user($request);
        $a = $help->find($slug, Chat::leads($user));
        abort_if($a === null, 404);
        $voted = DB::connection(Connections::cell())->table('team_help_feedback')->where('tenant_id', $user->tenant_id)->where('article', $slug)->where('user_id', $user->id)->value('helpful');

        return Inertia::render('team/help-article', ['article' => ['slug' => $a['slug'], 'title' => $a['title'], 'section' => $a['section'], 'summary' => $a['summary'],
            'html' => HelpCentre::html($a['body'])], 'voted' => $voted === null ? null : (bool) $voted,
            'related' => array_slice(array_values(array_filter($help->search($a['keywords'], Chat::leads($user), 4), fn (array $r): bool => $r['slug'] !== $slug)), 0, 3)]);
    }

    public function feedback(Request $request, string $slug, HelpCentre $help): RedirectResponse
    {
        $user = self::user($request);
        abort_if($help->find($slug, Chat::leads($user)) === null, 404);
        $data = $request->validate(['helpful' => ['required', 'boolean']]);
        DB::connection(Connections::cell())->table('team_help_feedback')->updateOrInsert(['tenant_id' => $user->tenant_id, 'article' => $slug, 'user_id' => $user->id],
            ['helpful' => (bool) $data['helpful'], 'created_at' => now(), 'updated_at' => now()]);

        return back();
    }

    public function ask(Request $request, SupportTickets $tickets): RedirectResponse
    {
        $data = $request->validate(['topic' => ['required', 'string'], 'subject' => ['required', 'string', 'max:160'], 'body' => ['required', 'string', 'max:5000'],
            'page' => ['nullable', 'string', 'max:200']]);
        $tickets->open(self::user($request), $data['topic'], $data['subject'], $data['body'], $data['page'] ?? null);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Thanks. We will answer by email, usually within one working day.']);

        return back();
    }

    /** SX5: what "Help me" shows for the page the person is on. */
    public function page(Request $request, PageHelp $pages): JsonResponse
    {
        $user = self::user($request);
        $data = $request->validate(['path' => ['required', 'string', 'max:300']]);

        // Practice mode (SX5) lives in the CRM: whether this person has practice people to remove.
        $practice = class_exists(PracticeClients::class) && app(PracticeClients::class)->has($user);

        return response()->json($pages->for($data['path'], Chat::leads($user)) + ['phone' => $user->phone, 'practice' => $practice]);
    }

    /** SX5: "Call me back" — a request to RightAlly support with the number and the best time. */
    public function callback(Request $request, SupportTickets $tickets): RedirectResponse
    {
        $user = self::user($request);
        $data = $request->validate(['phone' => ['required', 'string', 'regex:/^[0-9 ()+.\-]{7,24}$/'], 'when' => ['required', 'in:morning,afternoon,evening,any'],
            'note' => ['nullable', 'string', 'max:500'], 'page' => ['nullable', 'string', 'max:200']]);
        $when = ['morning' => 'in the morning', 'afternoon' => 'in the afternoon', 'evening' => 'in the early evening', 'any' => 'any time'][$data['when']];
        $tickets->open($user, 'callback', 'Please call me back '.$when, "Phone: {$data['phone']}\nBest time: {$when}\n".(trim((string) ($data['note'] ?? '')) !== '' ? "About: {$data['note']}" : 'About: help with this page'),
            $data['page'] ?? null);
        Inertia::flash('toast', ['type' => 'success', 'message' => "Thanks. Someone from RightAlly will call you {$when} on the next working day."]);

        return back();
    }

    /** SX6: concierge setup — send us your spreadsheet and we set it up for you. */
    public function concierge(Request $request, Concierge $concierge): RedirectResponse
    {
        $data = $request->validate(['file' => ['required', 'file', 'max:10240', 'mimes:csv,txt,xlsx,xls'], 'what' => ['required', 'in:contacts,listings,other'],
            'note' => ['nullable', 'string', 'max:1000']]);
        $concierge->send(self::user($request), $data['file'], $data['what'], $data['note'] ?? null);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Thanks. We will set it up for you, usually within two working days, and tell you by email when it is done.']);

        return back();
    }

    /** SX5: a printable, large-print cheat sheet of a how-to article. */
    public function print(Request $request, string $slug, HelpCentre $help): HttpResponse
    {
        $a = $help->find($slug, Chat::leads(self::user($request)));
        abort_if($a === null, 404);

        return response()->view('team::cheat-sheet', ['title' => $a['title'], 'summary' => $a['summary'], 'html' => HelpCentre::html($a['body'])]);
    }

    public function close(Request $request, string $ticket, SupportTickets $tickets): RedirectResponse
    {
        $tickets->close($ticket, self::user($request));

        return back();
    }

    private static function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User && Chat::allowed($user), 403);

        return $user;
    }
}
