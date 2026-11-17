<?php

declare(strict_types=1);

namespace Modules\Listings\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Core\Audit\AuditLogger;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\Attributes\CrossTenantAccess;
use Modules\Core\Tenancy\Models\Tenant;
use Modules\Listings\Access\MlsAccess;
use Modules\Listings\Feed\ResoFeed;
use Modules\Listings\Jobs\SyncMlsSource;
use Modules\Listings\Models\Listing;
use Modules\Listings\Models\MlsAuthorization;
use Modules\Listings\Models\MlsSource;
use Modules\Listings\Models\RulesProfile;

/** HQ → MLS connections: feeds, credentials (write-only), display rules, freshness and brokerage licences. */
#[CrossTenantAccess('HQ reviews MLS licence requests from every brokerage')]
final class HqMlsController
{
    public function index(): Response
    {
        $counts = Listing::query()->groupBy('mls_source_id')->selectRaw('mls_source_id, count(*) as total, sum(case when off_market_at is null and standard_status in (\'Active\', \'Active Under Contract\', \'Coming Soon\', \'Pending\') then 1 else 0 end) as on_market')
            ->get()->keyBy('mls_source_id');
        $runs = DB::connection(Connections::listings())->table('mls_sync_runs')->orderByDesc('id')->limit(200)->get()->groupBy('mls_source_id');
        $tenants = Tenant::query()->pluck('name', 'id');
        $sources = MlsSource::query()->orderBy('name')->get();

        return Inertia::render('hq/mls', [
            'sources' => $sources->map(fn (MlsSource $s): array => [
                'ulid' => $s->ulid, 'key' => $s->key, 'name' => $s->name, 'provider' => $s->provider, 'base_url' => $s->base_url, 'states' => $s->states,
                'originating_system' => $s->originating_system, 'status' => $s->status, 'has_credentials' => $s->credentials !== null,
                'sync_interval_minutes' => $s->sync_interval_minutes, 'rate_limit_per_hour' => $s->rate_limit_per_hour, 'rules_profile_id' => $s->rules_profile_id,
                'minutes_behind' => $s->minutesBehind(), 'full_sync_done' => $s->full_sync_done, 'last_error' => $s->last_error,
                'backoff_until' => $s->backoff_until?->toIso8601String(), 'listings' => (int) ($counts->get($s->id)->total ?? 0), 'on_market' => (int) ($counts->get($s->id)->on_market ?? 0),
                'runs' => collect($runs->get($s->id, []))->take(5)->map(fn ($r): array => ['kind' => $r->kind, 'result' => $r->result, 'received' => (int) $r->received,
                    'changed' => (int) $r->changed, 'removed' => (int) $r->removed, 'at' => (string) $r->started_at, 'error' => $r->error])->values(),
            ]),
            'profiles' => RulesProfile::query()->orderBy('name')->get()->map(fn (RulesProfile $p): array => $p->only(['id', 'name', 'program', 'attribution_template', 'disclaimer', 'logo_url',
                'sold_allowed', 'sold_delay_days', 'suppressed_fields', 'freshness_minutes', 'index_off_market'])),
            'licences' => MlsAuthorization::query()->orderByRaw("case status when 'pending' then 0 when 'active' then 1 else 2 end")->latest('id')->limit(200)->get()
                ->map(fn (MlsAuthorization $a): array => ['ulid' => $a->ulid, 'brokerage' => $tenants[$a->tenant_id] ?? 'Unknown', 'mls' => $sources->firstWhere('id', $a->mls_source_id)?->name,
                    'broker_mls_id' => $a->broker_mls_id, 'office_mls_ids' => $a->office_mls_ids ?? [], 'scopes' => $a->scopes, 'status' => $a->status,
                    'expires_on' => $a->expires_on?->toDateString(), 'agreement_ref' => $a->agreement_ref, 'requested' => $a->created_at?->toIso8601String()]),
            'providers' => MlsSource::PROVIDERS,
            'presets' => MlsSource::PRESETS,
            'scopes' => MlsAuthorization::SCOPES,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'key' => ['required', 'string', 'max:40', 'regex:/^[a-z0-9-]+$/', Rule::unique(Connections::listings().'.mls_sources', 'key')],
            'name' => ['required', 'string', 'max:120'], 'provider' => ['required', Rule::in(MlsSource::PROVIDERS)],
            'base_url' => ['nullable', 'url:https', 'max:300'], 'states' => ['required', 'array', 'min:1'], 'states.*' => ['string', 'size:2'],
            'originating_system' => ['nullable', 'string', 'max:60'], 'timezone' => ['required', 'timezone'],
            'sync_interval_minutes' => ['required', 'integer', 'min:1', 'max:1440'], 'rate_limit_per_hour' => ['required', 'integer', 'min:1', 'max:100000'],
        ]);
        $data['base_url'] = ($data['base_url'] ?? '') !== '' ? $data['base_url'] : MlsSource::PRESETS[$data['provider']]['base_url'];
        if ($data['base_url'] === null) {
            return back()->withErrors(['base_url' => 'Enter the address the MLS gave you for this feed.']);
        }
        $source = MlsSource::query()->create([...$data, 'states' => array_map('strtoupper', $data['states'])]);
        app(AuditLogger::class)->record('listings.mls.source_added', metadata: ['key' => $source->key], severity: 'notice');

        return back();
    }

    public function update(Request $request, MlsSource $source): RedirectResponse
    {
        $data = $request->validate(['status' => ['nullable', Rule::in(['active', 'paused'])], 'rules_profile_id' => ['nullable', 'integer'],
            'sync_interval_minutes' => ['nullable', 'integer', 'min:1', 'max:1440'], 'rate_limit_per_hour' => ['nullable', 'integer', 'min:1', 'max:100000']]);
        if (array_key_exists('rules_profile_id', $data) && $data['rules_profile_id'] !== null) {
            abort_unless(RulesProfile::query()->whereKey($data['rules_profile_id'])->exists(), 422);
        }
        $source->fill(array_filter($data, fn ($v): bool => $v !== null) + (array_key_exists('rules_profile_id', $data) ? ['rules_profile_id' => $data['rules_profile_id']] : []))->save();
        app(AuditLogger::class)->record('listings.mls.source_updated', metadata: ['key' => $source->key, 'changes' => array_keys($data)], severity: 'notice');

        return back();
    }

    public function credentials(Request $request, MlsSource $source): RedirectResponse
    {
        $data = $request->validate(['token' => ['nullable', 'string', 'max:2000'], 'client_id' => ['nullable', 'string', 'max:200'], 'client_secret' => ['nullable', 'string', 'max:500'],
            'token_url' => ['nullable', 'url:https', 'max:300'], 'scope' => ['nullable', 'string', 'max:100']]);
        if (($data['token_url'] ?? '') === '' && ($data['client_id'] ?? '') !== '') {
            $data['token_url'] = MlsSource::PRESETS[$source->provider]['token_url'] ?? null;
        }
        $creds = ($data['token'] ?? '') !== '' ? ['token' => (string) $data['token']]
            : (($data['client_id'] ?? '') !== '' && ($data['token_url'] ?? '') !== '' ? array_filter(['client_id' => $data['client_id'], 'client_secret' => $data['client_secret'] ?? '',
                'token_url' => $data['token_url'], 'scope' => $data['scope'] ?? 'api']) : null);
        if ($creds === null) {
            return back()->withErrors(['token' => 'Enter a token, or a client ID with its token URL.']);
        }
        $source->forceFill(['credentials' => $creds])->save();
        app(AuditLogger::class)->record('listings.mls.credentials_changed', metadata: ['key' => $source->key], severity: 'critical');

        return back();
    }

    public function test(MlsSource $source, ResoFeed $feed): RedirectResponse
    {
        $error = $feed->test($source);
        Inertia::flash('toast', $error === null ? ['type' => 'success', 'message' => "{$source->name}: connected."] : ['type' => 'error', 'message' => "{$source->name}: {$error}"]);

        return back();
    }

    public function sync(MlsSource $source): RedirectResponse
    {
        SyncMlsSource::dispatch($source->id);
        Inertia::flash('toast', ['type' => 'success', 'message' => "Sync started for {$source->name}."]);

        return back();
    }

    public function saveProfile(Request $request): RedirectResponse
    {
        $data = $request->validate(['id' => ['nullable', 'integer'], 'name' => ['required', 'string', 'max:80'], 'program' => ['required', Rule::in(['idx', 'vow'])],
            'attribution_template' => ['required', 'string', 'max:200'], 'disclaimer' => ['nullable', 'string', 'max:3000'], 'logo_url' => ['nullable', 'url:https', 'max:500'],
            'sold_allowed' => ['required', 'boolean'], 'sold_delay_days' => ['required', 'integer', 'min:0', 'max:3650'], 'suppressed_fields' => ['nullable', 'array'],
            'suppressed_fields.*' => ['string', Rule::in(['close_price', 'original_list_price', 'lot_size', 'year_built', 'subdivision', 'public_remarks', 'days_on_market', 'virtual_tour'])],
            'freshness_minutes' => ['required', 'integer', 'min:5', 'max:10080'], 'index_off_market' => ['required', 'boolean']]);
        $profile = isset($data['id']) ? RulesProfile::query()->findOrFail((int) $data['id']) : new RulesProfile;
        unset($data['id']);
        $profile->fill($data)->save();
        app(AuditLogger::class)->record('listings.mls.rules_saved', metadata: ['profile' => $profile->name], severity: 'notice');

        return back();
    }

    public function approve(Request $request, MlsAuthorization $licence, MlsAccess $access): RedirectResponse
    {
        $data = $request->validate(['scopes' => ['required', 'array'], 'scopes.*' => ['string'], 'expires_on' => ['nullable', 'date'], 'agreement_ref' => ['nullable', 'string', 'max:120']]);
        $access->approve($licence, array_values($data['scopes']), $data['expires_on'] ?? null, $data['agreement_ref'] ?? null, self::user($request));

        return back();
    }

    public function revoke(Request $request, MlsAuthorization $licence, MlsAccess $access): RedirectResponse
    {
        $data = $request->validate(['note' => ['nullable', 'string', 'max:300']]);
        $access->revoke($licence, (string) ($data['note'] ?? ''), self::user($request));

        return back();
    }

    private static function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
