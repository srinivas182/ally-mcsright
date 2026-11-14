<?php

declare(strict_types=1);

namespace Modules\Listings\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Core\Identity\Enums\UserKind;
use Modules\Core\Tenancy\TenantContext;
use Modules\Listings\Access\MlsAccess;
use Modules\Listings\Search\ListingSearch;

/** Back office → Listings: search the brokerage's licensed MLSs with photos and price history. */
final class ListingsController
{
    public function index(Request $request, ListingSearch $search, TenantContext $tenants, MlsAccess $access): Response
    {
        self::user($request);
        $found = $search->search($tenants->id(), $request->query());

        return Inertia::render('listings/index', $found + ['licensed' => $access->licensedSources($tenants->id()) !== [], 'sorts' => ListingSearch::SORTS, 'statuses' => ListingSearch::STATUSES]);
    }

    public function show(Request $request, string $listing, ListingSearch $search, TenantContext $tenants): Response
    {
        self::user($request);
        $found = $search->find($tenants->id(), $listing);
        abort_if($found === null, 404);

        return Inertia::render('listings/show', ['listing' => $found]);
    }

    private static function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User && in_array($user->kind, [UserKind::Owner, UserKind::Staff, UserKind::Agent, UserKind::Assistant], true), 403);

        return $user;
    }
}
