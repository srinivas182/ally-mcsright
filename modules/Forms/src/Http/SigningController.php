<?php

declare(strict_types=1);

namespace Modules\Forms\Http;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\TenantContext;
use Modules\Forms\Identity\IdentityChecks;
use Modules\Forms\Negotiations;
use Modules\Forms\Pdf;
use Modules\Forms\Signing;
use Modules\Forms\Summaries;
use Modules\Forms\Templates;

/** The signer's side (no sign-in; secret link) and public document verification. */
final class SigningController
{
    public function show(Request $request, string $token, Signing $signing): Response
    {
        ['signer' => $s, 'packet' => $p] = $signing->byToken($token, true);
        if ($p->status === 'sent') {
            $signing->viewed($token, $request->ip());
        }
        $db = DB::connection(Connections::cell());
        $fields = [];
        foreach ($db->table('form_packet_forms')->where('packet_id', $p->id)->orderBy('sort')->get() as $f) {
            $t = $db->table('form_templates')->where('id', $f->template_id)->first();
            foreach ((array) json_decode((string) $t?->fields, true) as $field) {
                if (in_array($field['type'], Templates::SIGNER_FIELDS, true) && $field['role'] === $s->role) {
                    $fields[] = ['form' => (string) $t?->name, 'label' => (string) $field['label'], 'type' => (string) $field['type']];
                }
            }
        }

        return Inertia::render('public/sign', [
            'token' => $token, 'brokerage' => app(TenantContext::class)->current()->name,
            'packet' => ['title' => $p->title, 'status' => $p->status, 'verify' => $p->verify],
            'signer' => ['name' => $s->name, 'consented' => $s->consent_at !== null, 'verified' => $s->verified_at !== null, 'signed' => $s->signed_at !== null,
                'phone_last4' => $s->phone !== null ? substr((string) $s->phone, -4) : null, 'id_required' => (bool) $s->id_required, 'id_status' => $s->id_status],
            'fields' => $fields, 'disclosure' => Signing::ESIGN_DISCLOSURE,
            'summary' => $s->signed_at === null ? Summaries::forSigner($p) : null,
            'changes' => $s->signed_at !== null && $p->status === 'sent' ? Negotiations::pendingFor((int) $s->id) : [],
        ]);
    }

    public function consent(Request $request, string $token, Signing $signing): RedirectResponse
    {
        $request->validate(['agree' => ['accepted']]);
        $signing->consent($token, $request->ip());
        $signing->sendCode($token);

        return back();
    }

    public function code(string $token, Signing $signing): RedirectResponse
    {
        $signing->sendCode($token);

        return back();
    }

    public function verify(Request $request, string $token, Signing $signing): RedirectResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:10']]);
        $signing->verifyCode($token, $data['code'], $request->ip());

        return back();
    }

    public function sign(Request $request, string $token, Signing $signing): RedirectResponse
    {
        $data = $request->validate(['typed' => ['nullable', 'string', 'max:160'], 'drawn' => ['nullable', 'string', 'max:400000'], 'initials' => ['required', 'string', 'max:12']]);
        $signing->sign($token, $data['typed'] ?? null, $data['drawn'] ?? null, $data['initials'], $request->ip(), $request->userAgent(), $request->getSchemeAndHttpHost());

        return back();
    }

    /** Sends the signer to the identity service (government ID and a live selfie). */
    public function identity(Request $request, string $token, IdentityChecks $identity): \Symfony\Component\HttpFoundation\Response
    {
        return Inertia::location($identity->start($token, $request->getSchemeAndHttpHost()));
    }

    public function identityReturn(Request $request, string $token, IdentityChecks $identity): RedirectResponse
    {
        $identity->finish($token, $request->ip());

        return redirect('/sign/'.$token);
    }

    /** A signer who already signed initials the changes made since. */
    public function acknowledge(Request $request, string $token, Negotiations $negotiations): RedirectResponse
    {
        $data = $request->validate(['initials' => ['required', 'string', 'max:12']]);
        $negotiations->acknowledge($token, $data['initials'], $request->ip(), $request->getSchemeAndHttpHost());

        return back();
    }

    public function decline(Request $request, string $token, Signing $signing): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:300']]);
        $signing->decline($token, $data['reason'], $request->ip());

        return back();
    }

    /** The documents as they stand (values, and signatures so far), or the sealed copy once complete. */
    public function document(string $token, Signing $signing): \Symfony\Component\HttpFoundation\Response
    {
        ['packet' => $p] = $signing->byToken($token, true);
        if ($p->status === 'completed' && $p->sealed_path !== null) {
            return response((string) Storage::disk('local')->get((string) $p->sealed_path), 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="signed.pdf"']);
        }
        $db = DB::connection(Connections::cell());
        $forms = [];
        foreach ($db->table('form_packet_forms')->where('packet_id', $p->id)->orderBy('sort')->get() as $f) {
            $t = $db->table('form_templates')->where('id', $f->template_id)->first();
            $values = (array) json_decode((string) $f->values, true);
            $stamps = [];
            foreach ((array) json_decode((string) $t?->fields, true) as $field) {
                if (! in_array($field['type'], Templates::SIGNER_FIELDS, true)) {
                    $stamps[] = ['page' => (int) $field['page'], 'x' => (float) $field['x'], 'y' => (float) $field['y'], 'w' => (float) $field['w'], 'h' => (float) $field['h'],
                        'text' => $field['type'] === 'checkbox' ? (($values[$field['key']] ?? '') !== '' ? 'X' : '') : (string) ($values[$field['key']] ?? '')];
                }
            }
            $forms[] = ['pdf' => (string) Storage::disk('local')->get((string) $t?->path), 'stamps' => $stamps];
        }

        return response(Pdf::assemble($forms, ['Not yet signed by everyone. The signed copy, with its full signature certificate, is emailed when complete.']), 200,
            ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="documents.pdf"']);
    }

    public function verifyPage(): Response
    {
        return Inertia::render('public/verify-document', ['result' => null]);
    }

    public function verifyUpload(Request $request, Signing $signing): Response
    {
        $request->validate(['file' => ['required', 'file', 'max:30720']]);

        return Inertia::render('public/verify-document', ['result' => $signing->verifyDocument((string) file_get_contents((string) $request->file('file')?->getRealPath()))]);
    }
}
