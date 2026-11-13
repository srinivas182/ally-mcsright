<?php

declare(strict_types=1);

namespace Modules\Forms\Http;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Core\Support\Connections;
use Modules\Forms\Packets;
use Modules\Forms\Signing;
use Modules\Forms\Templates;
use Modules\Transactions\Models\Deal;

final class FormsController
{
    public function templates(Request $request): Response
    {
        abort_unless(Templates::manages(self::user($request)), 403);
        $db = DB::connection(Connections::cell());

        return Inertia::render('forms/templates', [
            'templates' => $db->table('form_templates')->where('active', true)->orderBy('name')->get()->map(fn ($t): array => ['ulid' => $t->ulid, 'name' => $t->name,
                'pages' => (int) $t->pages, 'version' => (int) $t->version, 'fields' => json_decode((string) $t->fields, true), 'page_sizes' => json_decode((string) $t->page_sizes, true),
                'checklist_label' => $t->checklist_label])->values(),
            'clauses' => $db->table('form_clauses')->orderBy('title')->get(['ulid', 'title', 'body']),
            'bindings' => Packets::bindings(), 'types' => Templates::TYPES,
        ]);
    }

    public function upload(Request $request, Templates $templates): RedirectResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:160'], 'checklist_label' => ['nullable', 'string', 'max:160'], 'file' => ['required', 'file', 'max:20480']]);
        $templates->upload($data['name'], $request->file('file'), $data['checklist_label'] ?? null, self::user($request));

        return back();
    }

    public function fields(Request $request, string $template, Templates $templates): RedirectResponse
    {
        $data = $request->validate(['fields' => ['present', 'array', 'max:400']]);
        $templates->saveFields($template, array_values($data['fields']), self::user($request));
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Fields saved as a new version.']);

        return back();
    }

    public function clause(Request $request, Templates $templates): RedirectResponse
    {
        $data = $request->validate(['title' => ['required', 'string', 'max:120'], 'body' => ['required', 'string', 'max:10000']]);
        $templates->addClause($data['title'], $data['body'], self::user($request));

        return back();
    }

    /** A deal's forms: its packets, and starting a new one. */
    public function forDeal(Request $request, Deal $deal): Response
    {
        $user = self::user($request);
        abort_unless(Templates::manages($user) || $deal->agent_user_id === $user->id || $deal->agent_user_id === $user->assisted_user_id, 403);
        $db = DB::connection(Connections::cell());

        return Inertia::render('forms/deal', [
            'deal' => ['ulid' => $deal->ulid, 'street' => $deal->street],
            'packets' => $db->table('form_packets')->where('deal_id', $deal->id)->orderByDesc('id')->get(['ulid', 'title', 'status', 'created_at']),
            'templates' => $db->table('form_templates')->where('active', true)->orderBy('name')->get(['ulid', 'name']),
        ]);
    }

    public function createPacket(Request $request, Deal $deal, Packets $packets): RedirectResponse
    {
        $data = $request->validate(['title' => ['nullable', 'string', 'max:160'], 'templates' => ['required', 'array', 'min:1', 'max:20'], 'templates.*' => ['string', 'size:26']]);
        $ulid = $packets->create($deal, (string) ($data['title'] ?? ''), array_values($data['templates']), self::user($request));

        return redirect("/forms/packets/{$ulid}");
    }

    public function packet(Request $request, string $packet, Packets $packets): Response
    {
        $user = self::user($request);
        $db = DB::connection(Connections::cell());
        $row = $db->table('form_packets')->where('ulid', $packet)->first();
        abort_if($row === null, 404);
        $deal = Deal::query()->findOrFail((int) $row->deal_id);

        return Inertia::render('forms/packet', [
            'packet' => ['ulid' => $row->ulid, 'title' => $row->title, 'status' => $row->status, 'deal' => ['ulid' => $deal->ulid, 'street' => $deal->street]],
            'forms' => $db->table('form_packet_forms')->where('packet_id', $row->id)->orderBy('sort')->get()->map(function ($f) use ($db): array {
                $t = $db->table('form_templates')->where('id', $f->template_id)->first();

                return ['id' => (int) $f->id, 'name' => (string) $t?->name, 'values' => (object) json_decode((string) $f->values, true),
                    'fields' => array_values(array_filter((array) json_decode((string) $t?->fields, true), fn ($x) => ! in_array($x['type'], Templates::SIGNER_FIELDS, true)))];
            })->values(),
            'signers' => $db->table('form_signers')->where('packet_id', $row->id)->orderBy('sign_order')->get(['ulid', 'role', 'name', 'email', 'phone', 'sign_order', 'status', 'signed_at', 'decline_reason']),
            'events' => $db->table('form_events')->where('packet_id', $row->id)->orderByDesc('id')->limit(50)->get(['action', 'detail', 'at']),
            'problems' => $packets->check($packet, $user),
            'clauses' => $db->table('form_clauses')->orderBy('title')->get(['title', 'body']),
        ]);
    }

    public function values(Request $request, string $packet, int $form, Packets $packets): RedirectResponse
    {
        $data = $request->validate(['values' => ['present', 'array']]);
        $packets->setValues($packet, $form, array_map(fn ($v) => (string) $v, $data['values']), self::user($request));

        return back();
    }

    public function signers(Request $request, string $packet, Packets $packets): RedirectResponse
    {
        $data = $request->validate(['signers' => ['present', 'array', 'max:12'], 'signers.*.role' => ['required', 'string', 'max:30'], 'signers.*.name' => ['required', 'string', 'max:160'],
            'signers.*.email' => ['required', 'email'], 'signers.*.phone' => ['nullable', 'string', 'max:30'], 'signers.*.sign_order' => ['required', 'integer', 'min:1', 'max:12']]);
        $packets->setSigners($packet, array_values($data['signers']), self::user($request));

        return back();
    }

    public function preview(Request $request, string $packet, int $form, Packets $packets): \Symfony\Component\HttpFoundation\Response
    {
        return response($packets->preview($packet, $form, self::user($request)), 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="preview.pdf"']);
    }

    private static function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }

    public function send(Request $request, string $packet, Signing $signing): RedirectResponse
    {
        $data = $request->validate(['verify' => ['required', 'in:email,sms']]);
        $signing->send($packet, $data['verify'], self::user($request), $request->getSchemeAndHttpHost());
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Sent for signature.']);

        return back();
    }

    public function void(Request $request, string $packet, Signing $signing): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:200']]);
        $signing->void($packet, $data['reason'], self::user($request));

        return back();
    }

    public function inPerson(Request $request, string $packet, string $signer, Signing $signing): \Symfony\Component\HttpFoundation\Response
    {
        return Inertia::location($signing->inPerson($packet, $signer, self::user($request), $request->getSchemeAndHttpHost()));
    }

    public function signed(Request $request, string $packet, Packets $packets): \Symfony\Component\HttpFoundation\Response
    {
        $packets->check($packet, self::user($request)); // access check
        $path = DB::connection(Connections::cell())->table('form_packets')->where('ulid', $packet)->where('status', 'completed')->value('sealed_path');
        abort_if($path === null, 404);

        return response((string) Storage::disk('local')->get((string) $path), 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'attachment; filename="signed.pdf"']);
    }
}
