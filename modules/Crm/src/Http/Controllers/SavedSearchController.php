<?php

declare(strict_types=1);

namespace Modules\Crm\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Inertia\Inertia;
use Modules\Core\Identity\Enums\UserKind;
use Modules\Core\Tenancy\TenantContext;
use Modules\Crm\ContactVisibility;
use Modules\Crm\Models\Contact;
use Modules\Crm\Models\SavedSearch;
use Modules\Crm\Searches\SavedSearches;

/** Saved searches on contacts, "send to client", and the public one-click unsubscribe (specs/grow/08). */
final class SavedSearchController
{
    public function store(Request $request, Contact $contact, SavedSearches $searches): RedirectResponse
    {
        $data = $request->validate(['name' => ['nullable', 'string', 'max:100'], 'frequency' => ['required', 'string'], 'q' => ['nullable', 'string', 'max:80'],
            'min_price' => ['nullable', 'numeric'], 'max_price' => ['nullable', 'numeric'], 'beds' => ['nullable', 'integer'], 'baths' => ['nullable', 'integer'],
            'min_sqft' => ['nullable', 'integer'], 'statuses' => ['nullable'], 'bounds' => ['nullable', 'string', 'max:100']]);
        $searches->create($contact, $data, (string) ($data['name'] ?? ''), (string) $data['frequency'], self::user($request));
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Saved search set up.']);

        return back();
    }

    public function update(Request $request, SavedSearch $search, SavedSearches $searches): RedirectResponse
    {
        $data = $request->validate(['frequency' => ['required', 'string']]);
        $searches->setFrequency($search, (string) $data['frequency'], self::user($request));

        return back();
    }

    public function destroy(Request $request, SavedSearch $search, ContactVisibility $visibility): RedirectResponse
    {
        abort_unless($visibility->canManage(self::user($request), $search->contact), 403);
        $search->delete();

        return back();
    }

    public function sendListing(Request $request, Contact $contact, SavedSearches $searches, ContactVisibility $visibility): RedirectResponse
    {
        $user = self::user($request);
        abort_unless($visibility->canSee($user, $contact), 404);
        $data = $request->validate(['listing' => ['required', 'string', 'size:26'], 'note' => ['nullable', 'string', 'max:2000']]);
        $searches->sendListing($contact, $data['listing'], (string) ($data['note'] ?? ''), $user);
        Inertia::flash('toast', ['type' => 'success', 'message' => "Sent to {$contact->name}."]);

        return back();
    }

    /** One-click unsubscribe: GET shows a confirm button (link scanners never unsubscribe anyone); POST stops the alerts. */
    public function stop(Request $request, string $search, SavedSearches $searches, TenantContext $tenants): Response
    {
        abort_unless($request->hasValidSignature(false), 403);
        $row = SavedSearch::query()->where('ulid', $search)->firstOrFail();
        if ($request->isMethod('post') && $row->unsubscribed_at === null) {
            $searches->unsubscribe($row);
        }

        return response()->view('crm::alerts.stop', ['done' => $request->isMethod('post') || $row->unsubscribed_at !== null, 'name' => $row->name,
            'brokerage' => $tenants->current()->name, 'action' => $request->getRequestUri()])->header('X-Robots-Tag', 'noindex');
    }

    private static function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User && $user->kind !== UserKind::HqStaff, 403);

        return $user;
    }
}
