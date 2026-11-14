<?php

declare(strict_types=1);

namespace Modules\Billing\Http;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Modules\Billing\Usage\UsageMeter;
use Modules\Core\Messaging\MessagingSettings;
use Modules\Core\Messaging\TwilioSignature;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\Attributes\CrossTenantAccess;
use Modules\Core\Tenancy\Models\Tenant;
use Modules\Core\Tenancy\TenantContext;

/**
 * Twilio's report of a finished call (specs/billing/03): settles the brokerage's call minutes from the estimate made
 * when the call was placed to the real length. Signature-checked; each call settles once.
 */
#[CrossTenantAccess('Twilio call reports arrive on the HQ host and are settled against the brokerage that placed the call')]
final class CallStatusController
{
    public function __invoke(Request $request, MessagingSettings $settings, TenantContext $tenants): Response
    {
        $credentials = $settings->credentials();
        /** @var array<string, string> $params */
        $params = array_map(fn ($v): string => is_scalar($v) ? (string) $v : '', $request->post());
        if ($credentials === null || ! TwilioSignature::valid($request->url(), $params, (string) $request->header('X-Twilio-Signature', ''), $credentials['token'])) {
            return response('', 403);
        }
        $sid = (string) ($params['CallSid'] ?? '');
        $call = DB::connection(Connections::control())->table('usage_calls')->where('call_sid', $sid)->whereNull('settled_at')->first();
        if ($call === null) {
            return response('', 204);
        }
        $actual = (int) ceil(max(0, (int) ($params['CallDuration'] ?? 0)) / 60);
        $claimed = DB::connection(Connections::control())->table('usage_calls')->where('id', $call->id)->whereNull('settled_at')
            ->update(['actual_minutes' => $actual, 'settled_at' => now()]);
        $tenant = Tenant::query()->whereKey((int) $call->tenant_id)->first();
        if ($claimed === 1 && $tenant !== null && $actual !== (int) $call->estimated_minutes) {
            $tenants->runAs($tenant, fn () => app(UsageMeter::class)->record('calls', $actual - (int) $call->estimated_minutes));
        }

        return response('', 204);
    }
}
