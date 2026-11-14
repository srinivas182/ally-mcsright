<?php

declare(strict_types=1);

namespace Modules\Core\Http\Controllers\Hq;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Core\Messaging\MessagingSettings;
use Modules\Core\Tenancy\Enums\TenantStatus;
use Modules\Core\Tenancy\Models\Tenant;

/** HQ: the Twilio account and numbers for texts and automated calls, and which brokerages may use them. */
final class MessagingController
{
    public function index(MessagingSettings $settings): Response
    {
        $allowed = $settings->tenants();

        return Inertia::render('hq/messaging', [
            'settings' => $settings->summary(),
            'tenants' => Tenant::query()->whereIn('status', [TenantStatus::Active, TenantStatus::Provisioning])->orderBy('name')->get(['id', 'ulid', 'name'])
                ->map(fn (Tenant $t): array => ['ulid' => $t->ulid, 'name' => $t->name, 'sms' => $allowed[$t->id]['sms'] ?? false, 'voice' => $allowed[$t->id]['voice'] ?? false])->values(),
        ]);
    }

    public function save(Request $request, MessagingSettings $settings): RedirectResponse
    {
        $data = $request->validate(['sid' => ['nullable', 'string', 'max:40'], 'token' => ['nullable', 'string', 'max:80'], 'sms_from' => ['nullable', 'string', 'max:20'],
            'voice_from' => ['nullable', 'string', 'max:20'], 'sms_enabled' => ['required', 'boolean'], 'voice_enabled' => ['required', 'boolean']]);
        $user = $request->user();
        abort_unless($user instanceof User, 403);
        $settings->save($data['sid'] ?? null, $data['token'] ?? null, $data['sms_from'] ?? null, $data['voice_from'] ?? null, (bool) $data['sms_enabled'], (bool) $data['voice_enabled'], $user);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Messaging settings saved.']);

        return back();
    }

    public function tenant(Request $request, Tenant $tenant, MessagingSettings $settings): RedirectResponse
    {
        $data = $request->validate(['sms' => ['required', 'boolean'], 'voice' => ['required', 'boolean']]);
        $settings->setTenant($tenant->id, (bool) $data['sms'], (bool) $data['voice']);

        return back();
    }
}
