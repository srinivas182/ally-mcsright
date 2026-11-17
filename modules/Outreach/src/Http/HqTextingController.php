<?php

declare(strict_types=1);

namespace Modules\Outreach\Http;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\Attributes\CrossTenantAccess;
use Modules\Core\Tenancy\Models\Tenant;
use Modules\Outreach\TextingRegistration;

/** HQ → Texting registrations (MK1): brokerages' A2P 10DLC answers to submit to the carrier registry, and the outcome. */
#[CrossTenantAccess('HQ submits each brokerage\'s texting registration to the carrier registry and records the outcome')]
final class HqTextingController
{
    public function index(TextingRegistration $texting): Response
    {
        $rows = DB::connection(Connections::control())->table('outreach_texting_registrations')->whereIn('status', ['submitted', 'approved', 'rejected'])->orderByDesc('submitted_at')->limit(200)->get();
        $names = Tenant::query()->whereIn('id', $rows->pluck('tenant_id')->all() ?: [0])->pluck('name', 'id');

        return Inertia::render('hq/texting', ['registrations' => $rows->map(function ($r) use ($texting, $names): array {
            $c = $texting->current((int) $r->tenant_id);

            return ['tenant_id' => (int) $r->tenant_id, 'brokerage' => (string) ($names[$r->tenant_id] ?? 'Brokerage'), 'status' => $c['status'], 'status_label' => $c['status_label'],
                'note' => $c['note'], 'submitted_at' => $c['submitted_at'], 'brand' => $c['brand'], 'campaign' => $c['campaign']];
        })->values()]);
    }

    public function decide(Request $request, TextingRegistration $texting, int $tenant): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);
        $data = $request->validate(['status' => ['required', 'in:approved,rejected'], 'note' => ['nullable', 'string', 'max:500']]);
        $texting->decide($tenant, $data['status'], $data['note'] ?? null, $user);

        return back();
    }
}
