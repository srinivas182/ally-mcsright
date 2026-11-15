<?php

declare(strict_types=1);

namespace Modules\Connect\Http;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Connect\AppRegistry;
use Modules\Connect\OAuth\ClientSettings;
use Modules\Connect\OAuth\Providers;
use Modules\Core\Connect\App;
use Modules\Core\Connect\AppCatalog;
use Modules\Core\Support\Connections;

/** HQ → App demand (specs/connect/00) and Connect setup (specs/connect/02). */
final class HqAppsController
{
    public function index(AppCatalog $catalog): Response
    {
        $rows = DB::connection(Connections::control())->table('hq_app_requests')
            ->selectRaw('app_key, count(*) as brokerages, sum(requests) as requests, max(last_requested_at) as last_at')->groupBy('app_key')->get()->keyBy('app_key');

        $apps = array_map(fn (App $a): array => ['key' => $a->key, 'name' => $a->name, 'category' => App::CATEGORIES[$a->category], 'status' => $a->status,
            'brokerages' => (int) ($rows[$a->key]->brokerages ?? 0), 'requests' => (int) ($rows[$a->key]->requests ?? 0),
            'last_at' => isset($rows[$a->key]) ? (string) $rows[$a->key]->last_at : null], $catalog->all());
        usort($apps, fn (array $x, array $y): int => [$y['brokerages'], $y['requests']] <=> [$x['brokerages'], $x['requests']]);

        return Inertia::render('hq/apps', ['apps' => $apps]);
    }

    /**
     * HQ → Connect setup: RightAlly's Google and Microsoft sign-in apps, with the return addresses to add and the
     * steps to follow in each console.
     */
    public function setup(AppCatalog $catalog, AppRegistry $registry): Response
    {
        $sso = 'https://'.config('core.sso_host');

        return Inertia::render('hq/connect', [
            'return_addresses' => [
                'connect' => Providers::redirectUri(),
                'google_sign_in' => $sso.'/oauth/google/callback',
                'microsoft_sign_in' => $sso.'/oauth/microsoft/callback',
            ],
            'providers' => ['google' => ClientSettings::status('google'), 'microsoft' => ClientSettings::status('microsoft')],
            'categories' => App::CATEGORIES,
            'apps' => array_map(fn (App $a): array => ['key' => $a->key, 'name' => $a->name, 'category' => $a->category, 'built' => $a->available(), 'built_in' => $a->builtIn(),
                'one_click' => $a->oauth !== null, 'needs' => $a->setup['needs'] ?? 'none', 'provider' => $a->setup['provider'] ?? null,
                'fields' => array_map(fn (array $f): array => ['key' => $f['key'], 'label' => $f['label'], 'secret' => $f['secret'] ?? true], $a->setup['fields'] ?? []),
                'steps' => $a->setup['steps'] ?? [], 'partner' => $a->setup['partner'] ?? null, 'docs' => $a->setup['docs'] ?? null,
                'return_address' => $a->oauth !== null ? Providers::redirectUri() : null] + $registry->status($a), $catalog->all()),
        ]);
    }

    /** HQ switches an app on or off and saves its RightAlly-level keys. */
    public function saveApp(Request $request, string $app, AppCatalog $catalog, AppRegistry $registry): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);
        $a = $catalog->find($app) ?? abort(404);
        $data = $request->validate(['enabled' => ['required', 'boolean'], 'keys' => ['nullable', 'array'], 'secret_expires_on' => ['nullable', 'date']]);
        $registry->save($a, (bool) $data['enabled'], (array) ($data['keys'] ?? []), $data['secret_expires_on'] ?? null, $user);
        Inertia::flash('toast', ['type' => 'success', 'message' => $a->name.((bool) $data['enabled']
            ? ($registry->ready($a) ? ' is on. Brokers and agents can connect it now.' : ' is on, but its setup isn\'t finished yet.')
            : ' is off. Existing connections are paused.')]);

        return back();
    }

    public function saveClient(Request $request, string $provider): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);
        $data = $request->validate(['client_id' => ['required', 'string', 'max:255'], 'client_secret' => ['nullable', 'string', 'max:500'],
            'secret_expires_on' => ['nullable', 'date']]);
        ClientSettings::save($provider, $data['client_id'], (string) ($data['client_secret'] ?? ''), $data['secret_expires_on'] ?? null, $user);
        Inertia::flash('toast', ['type' => 'success', 'message' => ($provider === 'google' ? 'Google' : 'Microsoft').' sign-in app saved.']);

        return back();
    }
}
