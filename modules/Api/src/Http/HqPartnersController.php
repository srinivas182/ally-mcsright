<?php

declare(strict_types=1);

namespace Modules\Api\Http;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Api\PartnerApps;
use Modules\Api\Scopes;
use Modules\Core\Support\Connections;

/** HQ → Partner apps (API1): register partners and give them their client id and secret once. */
final class HqPartnersController
{
    public function index(Request $request): Response
    {
        return Inertia::render('hq/partners', ['apps' => DB::connection(Connections::control())->table('api_partner_apps')->orderBy('name')->get()
            ->map(fn (\stdClass $a): array => ['client_id' => (string) $a->client_id, 'name' => (string) $a->name, 'company' => (string) $a->company, 'verified' => (bool) $a->verified,
                'active' => (bool) $a->active, 'scopes' => (array) json_decode((string) $a->scopes, true)])->values(),
            'scopes' => Scopes::ALL, 'made' => $request->session()->get('partnerMade')]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:80'], 'company' => ['required', 'string', 'max:120'], 'website' => ['required', 'url', 'max:200'],
            'description' => ['required', 'string', 'max:500'], 'scopes' => ['required', 'array', 'min:1'], 'scopes.*' => ['string'], 'redirects' => ['required', 'string', 'max:2000'],
            'verified' => ['sometimes', 'boolean']]);
        $user = $request->user();
        abort_unless($user instanceof User, 403);
        $made = PartnerApps::register($data['name'], $data['company'], $data['website'], $data['description'], array_values($data['scopes']),
            array_values(array_filter(array_map('trim', preg_split('/[\s,]+/', $data['redirects']) ?: []))), (bool) ($data['verified'] ?? false), $user);

        return back()->with('partnerMade', $made);
    }

    public function toggle(Request $request, string $client): RedirectResponse
    {
        $data = $request->validate(['active' => ['required', 'boolean']]);
        DB::connection(Connections::control())->table('api_partner_apps')->where('client_id', $client)->update(['active' => (bool) $data['active'], 'updated_at' => now()]);

        return back();
    }
}
