<?php

declare(strict_types=1);

namespace Modules\Appointments\Http;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Appointments\Consultations;
use Modules\Appointments\Recordings;
use Modules\Crm\ContactVisibility;
use Modules\Crm\Models\Contact;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Grow → Appointments (AP1, specs/listings/05). */
final class AppointmentsController
{
    public function index(Request $request, Consultations $consultations, ContactVisibility $visibility): Response
    {
        $user = self::user($request);
        $contact = is_string($request->query('contact')) ? Contact::query()->where('ulid', $request->query('contact'))->first() : null;

        return Inertia::render('appointments/index', ['mine' => $consultations->mine($user),
            'contact' => $contact !== null && $visibility->canSee($user, $contact) ? ['ulid' => $contact->ulid, 'name' => trim($contact->first_name.' '.$contact->last_name)] : null,
            'recent' => $visibility->query($user)->orderByDesc('last_activity_at')->limit(30)->get()->map(fn (Contact $c): array => ['ulid' => $c->ulid, 'name' => trim($c->first_name.' '.$c->last_name)])->values()]);
    }

    public function start(Request $request, Consultations $consultations): RedirectResponse
    {
        $data = $request->validate(['kind' => ['required', 'in:listing,buyer'], 'contact' => ['required', 'string', 'size:26']]);
        $ulid = $consultations->start($data['kind'], Contact::query()->where('ulid', $data['contact'])->firstOrFail(), self::user($request));

        return redirect('/appointments/'.$ulid);
    }

    public function show(Request $request, Consultations $consultations, string $consultation): Response
    {
        $user = self::user($request);
        $recordings = app(Recordings::class);

        return Inertia::render('appointments/show', ['c' => $consultations->show($consultation, $user),
            // VO2: recorded appointments with notes and coaching.
            'recording' => ['blocker' => $recordings->blocker(), 'wording' => Recordings::WORDING, 'list' => $recordings->forConsultation($consultations->open($consultation, $user), $user)]]);
    }

    public function save(Request $request, Consultations $consultations, string $consultation): RedirectResponse
    {
        $data = $request->validate(['step' => ['required', 'string', 'max:20'], 'values' => ['required', 'array']]);
        $consultations->save($consultation, $data['step'], (array) $data['values'], self::user($request));

        return back();
    }

    public function signed(Request $request, Consultations $consultations, string $consultation): RedirectResponse
    {
        $data = $request->validate(['signed_on' => ['required', 'date', 'before_or_equal:today'], 'file' => ['nullable', 'file', 'max:10240']]);
        $consultations->signed($consultation, $data['signed_on'], $request->file('file'), self::user($request));

        return back();
    }

    public function finish(Request $request, Consultations $consultations, string $consultation): RedirectResponse
    {
        $deal = $consultations->finish($consultation, $request->boolean('send_guide'), self::user($request));
        Inertia::flash('toast', ['type' => 'success', 'message' => $deal !== null ? 'Listing created. Next: List-IQ to get it ready.' : 'Done.']);

        return $deal !== null ? redirect('/deals/'.$deal) : back();
    }

    public function file(Request $request, Consultations $consultations, string $consultation): StreamedResponse
    {
        $path = $consultations->file($consultation, self::user($request)) ?? abort(404);

        return Storage::disk('local')->response($path, null, ['Cache-Control' => 'private, no-store']);
    }

    private static function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
