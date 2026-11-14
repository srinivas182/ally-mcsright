<?php

declare(strict_types=1);

namespace Modules\Listings\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Core\Identity\Enums\UserKind;
use Modules\Core\Tenancy\TenantContext;
use Modules\Listings\Access\MlsAccess;
use Modules\Listings\Models\MlsAuthorization;
use Modules\Listings\Models\MlsSource;
use Modules\Listings\Models\RulesProfile;

/** Settings → MLS: the brokerage requests access to its MLSs and sees status, freshness and the rules that apply (never credentials). */
final class MlsSettingsController
{
    public function index(Request $request, TenantContext $tenants, MlsAccess $access): Response
    {
        self::owner($request);
        $mine = MlsAuthorization::query()->where('tenant_id', $tenants->id())->get()->keyBy('mls_source_id');
        $profiles = RulesProfile::query()->get()->keyBy('id');

        return Inertia::render('settings/mls', [
            'sources' => MlsSource::query()->whereIn('status', ['active', 'setup'])->orderBy('name')->get()->map(function (MlsSource $s) use ($mine, $profiles): array {
                $a = $mine->get($s->id);
                $p = $s->rules_profile_id !== null ? $profiles->get($s->rules_profile_id) : null;

                return ['ulid' => $s->ulid, 'name' => $s->name, 'states' => $s->states, 'minutes_behind' => $s->minutesBehind(),
                    'licence' => $a !== null ? ['status' => $a->status, 'broker_mls_id' => $a->broker_mls_id, 'scopes' => $a->scopes, 'expires_on' => $a->expires_on?->toDateString(),
                        'note' => $a->decision_note] : null,
                    'rules' => $p !== null ? ['program' => $p->program, 'sold_allowed' => $p->sold_allowed, 'sold_delay_days' => $p->sold_delay_days, 'attribution' => $p->attribution_template] : null];
            }),
        ]);
    }

    public function request(Request $request, MlsSource $source, TenantContext $tenants, MlsAccess $access): RedirectResponse
    {
        $user = self::owner($request);
        $data = $request->validate(['broker_mls_id' => ['required', 'string', 'max:30'], 'office_mls_ids' => ['nullable', 'string', 'max:300'], 'agreement_ref' => ['nullable', 'string', 'max:120']]);
        $access->request($tenants->id(), $source, $data['broker_mls_id'], preg_split('/[\s,]+/', (string) ($data['office_mls_ids'] ?? '')) ?: [], $data['agreement_ref'] ?? null, $user);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Request sent. RightAlly confirms access with the MLS, usually within two business days.']);

        return back();
    }

    private static function owner(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User && $user->kind === UserKind::Owner, 403);

        return $user;
    }
}
