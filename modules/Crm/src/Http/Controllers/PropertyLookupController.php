<?php

declare(strict_types=1);

namespace Modules\Crm\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Billing\Credits\RateCard;
use Modules\Core\Identity\Enums\UserKind;
use Modules\Crm\PropertyData\PropertyData;

/** Grow → Property lookup (specs/connect/21, C8b): ATTOM facts and BatchData owners for one address. */
final class PropertyLookupController
{
    public function __construct(private readonly PropertyData $data) {}

    public function show(Request $request): Response
    {
        self::user($request);
        $rates = app(RateCard::class);

        return Inertia::render('crm/property-lookup', [
            'available' => $this->data->available(),
            'credits' => ['attom' => $rates->rate('data.property')['credits'], 'batchdata' => $rates->rate('data.owner')['credits']],
            'result' => $request->session()->get('property_lookup'),
        ]);
    }

    public function lookup(Request $request): RedirectResponse
    {
        $user = self::user($request);
        $in = $request->validate(['vendor' => ['required', 'in:attom,batchdata'], 'street' => ['required', 'string', 'max:120'], 'city' => ['nullable', 'string', 'max:60'],
            'state' => ['nullable', 'string', 'max:2'], 'zip' => ['nullable', 'string', 'max:10']]);
        $r = $this->data->lookup($user, (string) $in['vendor'], ['street' => (string) $in['street'], 'city' => (string) ($in['city'] ?? ''), 'state' => strtoupper((string) ($in['state'] ?? '')),
            'zip' => (string) ($in['zip'] ?? '')]);

        return redirect('/property-lookup')->with('property_lookup', ['vendor' => $in['vendor']] + $r);
    }

    private static function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User && in_array($user->kind, [UserKind::Owner, UserKind::Staff, UserKind::Agent], true), 403);

        return $user;
    }
}
