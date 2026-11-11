<?php

declare(strict_types=1);

namespace Modules\Crm\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Crm\Consent;
use Modules\Crm\ContactVisibility;
use Modules\Crm\Enums\Lifecycle;
use Modules\Crm\Models\Contact;
use Modules\Crm\Models\ContactBrief;
use Modules\Crm\SmartLists;

/**
 * "Work my list": go through a list one contact at a time. Moves forward by contact ID, so a
 * contact that leaves the list after being called never makes the agent skip someone.
 */
final class WorkListController
{
    public function show(Request $request, ContactVisibility $visibility, SmartLists $lists, Consent $consent): Response
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        $key = (string) $request->query('list', '');
        $filters = $key !== '' ? $lists->filtersFor($key, $user) : [];
        abort_if($filters === null, 404);
        $after = (int) $request->query('after', 0);

        $base = $lists->apply($visibility->query($user), $filters)->where('lifecycle', '!=', Lifecycle::Archived);
        $total = (clone $base)->count();
        $done = (clone $base)->where('id', '<=', $after)->count();
        $contact = (clone $base)->where('id', '>', $after)->orderBy('id')->first();
        $brief = $contact !== null ? ContactBrief::query()->where('contact_id', $contact->id)->first() : null;

        return Inertia::render('crm/contacts/work', [
            'list' => ['key' => $key, 'name' => $key === '' ? 'All contacts' : collect($lists->listsFor($user))->firstWhere('key', $key)['name'] ?? 'List'],
            'position' => ['done' => $done, 'total' => $total],
            'contact' => $contact !== null ? [
                'id' => $contact->id, 'ulid' => $contact->ulid, 'name' => $contact->name, 'phone' => $contact->primary_phone, 'email' => $contact->primary_email,
                'lifecycle' => $contact->lifecycle->value, 'last_activity_at' => $contact->last_activity_at?->toIso8601String(),
                'can_call' => $consent->canCall($contact), 'can_text' => $consent->canText($contact),
            ] : null,
            'brief' => $brief !== null ? ['content' => $brief->content, 'stale' => $brief->stale] : null,
        ]);
    }
}
