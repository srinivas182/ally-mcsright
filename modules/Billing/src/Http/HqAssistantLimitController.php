<?php

declare(strict_types=1);

namespace Modules\Billing\Http;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Modules\Billing\Usage\AssistantAllowance;
use Modules\Core\Audit\AuditLogger;
use Modules\Core\Tenancy\Models\Tenant;

/** AO1: HQ › Brokerages › a brokerage › Website assistant: its own monthly number of conversations, or the package's. */
final class HqAssistantLimitController
{
    public function __invoke(Request $request, Tenant $tenant): RedirectResponse
    {
        $data = $request->validate(['monthly' => ['nullable', 'integer', 'min:0', 'max:'.AssistantAllowance::MAX]],
            ['monthly.*' => 'Type a whole number of conversations a month, or leave it empty to use the package\'s number.']);
        $before = AssistantAllowance::for($tenant->id);
        $monthly = isset($data['monthly']) ? (int) $data['monthly'] : null;
        AssistantAllowance::set($tenant->id, $monthly, $request->user()?->id);
        app(AuditLogger::class)->record('billing.assistant_limit.changed', metadata: ['tenant' => $tenant->id, 'from' => $before['override'], 'to' => $monthly], severity: 'notice');
        Inertia::flash('toast', ['type' => 'success', 'message' => $monthly === null
            ? "{$tenant->name} uses its package's number again ({$before['package']} conversations a month)."
            : "{$tenant->name} can now have {$monthly} assistant conversations a month."]);

        return back();
    }
}
