<?php

declare(strict_types=1);

namespace Modules\Team\Http;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Core\Support\Connections;
use Modules\Team\Chat;
use Modules\Team\Help\HelpCentre;
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
