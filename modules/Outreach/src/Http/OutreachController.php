<?php

declare(strict_types=1);

namespace Modules\Outreach\Http;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Core\Identity\Enums\UserKind;
use Modules\Crm\ContactVisibility;
use Modules\Crm\Models\Contact;
use Modules\Crm\SmartLists;
use Modules\Outreach\BookingLinks;
use Modules\Outreach\CallCards;
use Modules\Outreach\CallQueue;
use Modules\Outreach\TextingRegistration;
use Modules\Outreach\Videos;
use Modules\Sites\SiteSetup;

/** Outreach (MK1, specs/crm/20): booking links, video messages, the call queue and texting registration. */
final class OutreachController
{
    public function index(Request $request, BookingLinks $links, Videos $videos, TextingRegistration $texting): Response
    {
        $user = self::user($request);

        return Inertia::render('outreach/index', ['booking' => $links->forUser($user), 'videos' => count($videos->forUser($user)),
            'website' => app(SiteSetup::class)->liveHost() !== null, 'texting' => self::manager($user) ? $texting->current()['status_label'] : null]);
    }

    public function booking(Request $request, BookingLinks $links): Response
    {
        return Inertia::render('outreach/booking', ['link' => $links->forUser(self::user($request)), 'kinds' => BookingLinks::KINDS,
            'website' => app(SiteSetup::class)->liveHost() !== null]);
    }

    public function saveBooking(Request $request, BookingLinks $links): RedirectResponse
    {
        $data = $request->validate(['slug' => ['required', 'string', 'max:60'], 'title' => ['required', 'string', 'max:120'], 'intro' => ['nullable', 'string', 'max:500'],
            'kind' => ['required', 'string'], 'active' => ['required', 'boolean']]);
        $links->save(self::user($request), $data);
        Inertia::flash('toast', ['type' => 'success', 'message' => $data['active'] ? 'Your booking link is on.' : 'Saved.']);

        return back();
    }

    public function videos(Request $request, Videos $videos, ContactVisibility $visibility): Response
    {
        $user = self::user($request);
        $contact = is_string($request->query('contact')) ? Contact::query()->where('ulid', $request->query('contact'))->first() : null;

        return Inertia::render('outreach/videos', ['videos' => $videos->forUser($user), 'website' => app(SiteSetup::class)->liveHost() !== null,
            'contact' => $contact !== null && $visibility->canSee($user, $contact) ? ['ulid' => $contact->ulid, 'name' => trim($contact->first_name.' '.$contact->last_name)] : null]);
    }

    public function storeVideo(Request $request, Videos $videos, ContactVisibility $visibility): RedirectResponse
    {
        $user = self::user($request);
        $data = $request->validate(['video' => ['required', 'file', 'max:153600'], 'title' => ['required', 'string', 'max:120'], 'message' => ['nullable', 'string', 'max:500'],
            'contact' => ['nullable', 'string', 'size:26']]);
        $contact = ($data['contact'] ?? null) !== null ? Contact::query()->where('ulid', $data['contact'])->first() : null;
        abort_if($contact !== null && ! $visibility->canSee($user, $contact), 403);
        $videos->store($user, $request->file('video'), $data['title'], $data['message'] ?? null, $contact);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Video saved. Copy its link to send it.']);

        return back();
    }

    public function removeVideo(Request $request, Videos $videos, string $video): RedirectResponse
    {
        $videos->remove(self::user($request), $video);

        return back();
    }

    public function calls(Request $request, CallQueue $queue, SmartLists $lists): Response
    {
        $user = self::user($request);
        $key = is_string($request->query('list')) ? $request->query('list') : null;

        return Inertia::render('outreach/calls', ['lists' => $lists->listsFor($user), 'list' => $key, 'queue' => $key !== null ? $queue->build($user, $key) : null,
            'outcomes' => CallQueue::OUTCOMES, 'cards' => CallCards::all()]); // LN4: live call cards
    }

    public function logCall(Request $request, CallQueue $queue, string $contact): RedirectResponse
    {
        $data = $request->validate(['outcome' => ['required', 'string'], 'note' => ['nullable', 'string', 'max:2000']]);
        $queue->log(self::user($request), Contact::query()->where('ulid', $contact)->firstOrFail(), $data['outcome'], $data['note'] ?? null);

        return back();
    }

    public function texting(Request $request, TextingRegistration $texting): Response
    {
        abort_unless(self::manager(self::user($request)), 403);
        $current = $texting->current();
        $current['brand']['ein'] = ($current['brand']['ein'] ?? '') !== '' ? '•••••'.substr($current['brand']['ein'], -4) : ''; // never shown again

        return Inertia::render('outreach/texting', ['registration' => $current, 'types' => TextingRegistration::BUSINESS_TYPES, 'statuses' => TextingRegistration::STATUSES]);
    }

    public function saveTexting(Request $request, TextingRegistration $texting): RedirectResponse
    {
        $user = self::user($request);
        abort_unless(self::manager($user), 403);
        $in = $request->validate(['legal_name' => ['nullable', 'string', 'max:200'], 'business_type' => ['nullable', 'string', 'max:30'], 'ein' => ['nullable', 'string', 'max:20'],
            'website' => ['nullable', 'string', 'max:200'], 'street' => ['nullable', 'string', 'max:200'], 'city' => ['nullable', 'string', 'max:80'], 'state' => ['nullable', 'string', 'max:2'],
            'zip' => ['nullable', 'string', 'max:10'], 'contact_name' => ['nullable', 'string', 'max:120'], 'contact_email' => ['nullable', 'email', 'max:150'],
            'contact_phone' => ['nullable', 'string', 'max:30'], 'description' => ['nullable', 'string', 'max:1000'], 'sample_1' => ['nullable', 'string', 'max:320'],
            'sample_2' => ['nullable', 'string', 'max:320'], 'opt_in' => ['nullable', 'string', 'max:1000'], 'volume' => ['nullable', 'string', 'max:20'], 'submit' => ['required', 'boolean']]);
        if (str_starts_with((string) ($in['ein'] ?? ''), '•')) {
            $in['ein'] = '';
        }
        $texting->save($in, (bool) $in['submit'], $user);
        Inertia::flash('toast', ['type' => 'success', 'message' => $in['submit'] ? 'Sent. We will submit it to the carriers and tell you when it is approved.' : 'Saved.']);

        return back();
    }

    private static function manager(User $user): bool
    {
        return $user->isKind(UserKind::Owner) || $user->isKind(UserKind::Staff);
    }

    private static function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
