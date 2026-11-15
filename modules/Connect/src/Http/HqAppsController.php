<?php

declare(strict_types=1);

namespace Modules\Connect\Http;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Billing\Models\Package;
use Modules\Connect\AppRegistry;
use Modules\Connect\OAuth\ClientSettings;
use Modules\Connect\OAuth\Providers;
use Modules\Core\Connect\App;
use Modules\Core\Connect\AppCatalog;
use Modules\Core\Connect\AppWebhooks;
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
                'return_address' => $a->oauth !== null ? Providers::redirectUri() : null, 'request' => self::request($a),
                'webhook_address' => $a->driver !== null && is_subclass_of($a->driver, AppWebhooks::class) ? 'https://'.config('core.sso_host').'/connect/apps/'.$a->key.'/webhook' : null,
                'price_cents' => $registry->price($a)['cents'] ?? null, 'included_packages' => $registry->price($a)['packages'] ?? []] + $registry->status($a), $catalog->all()),
            'packages' => Package::query()->where('active', true)->orderBy('monthly_cents')->get(['id', 'name'])->map(fn (Package $p): array => ['id' => $p->id, 'name' => $p->name])->all(),
        ]);
    }

    /**
     * The ready-written request to the vendor (HQ sends it from their own mail, or opens the vendor's form).
     *
     * @return array{to: string|null, url: string|null, subject: string, body: string}|null
     */
    private static function request(App $a): ?array
    {
        $r = $a->setup['request'] ?? null;

        return $r === null ? null : ['to' => $r['to'] ?? null, 'url' => $r['url'] ?? null, 'subject' => $r['subject'],
            'body' => str_replace('{return_address}', Providers::redirectUri(), $r['body'])];
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

    /** HQ sets an app free for everyone, or a monthly add-on with the packages that include it (specs/connect/14). */
    public function savePrice(Request $request, string $app, AppCatalog $catalog, AppRegistry $registry): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);
        $a = $catalog->find($app) ?? abort(404);
        $data = $request->validate(['paid' => ['required', 'boolean'], 'price' => ['nullable', 'required_if:paid,true', 'numeric', 'min:1', 'max:10000'],
            'packages' => ['array'], 'packages.*' => ['integer']], ['price.required_if' => 'Enter the monthly price in dollars.']);
        $cents = (bool) $data['paid'] ? (int) round((float) $data['price'] * 100) : null;
        $registry->savePrice($a, $cents, array_values(array_map('intval', (array) ($data['packages'] ?? []))), $user);
        Inertia::flash('toast', ['type' => 'success', 'message' => $cents === null ? $a->name.' is free for everyone.' : $a->name.' is now $'.number_format($cents / 100, 2).' a month.']);

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
