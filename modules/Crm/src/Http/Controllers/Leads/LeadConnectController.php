<?php

declare(strict_types=1);

namespace Modules\Crm\Http\Controllers\Leads;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Modules\Crm\Leads\SpeedToLead;
use Modules\Crm\Models\Lead;

/** Twilio calls this (signed, 30-minute link) when the agent presses a key on the speed-to-lead call. */
final class LeadConnectController
{
    public function __invoke(Request $request, string $lead, int $agent, SpeedToLead $speed): Response
    {
        abort_unless($request->hasValidSignature(false), 403);
        $row = Lead::query()->where('ulid', $lead)->with('contact')->firstOrFail();
        $user = User::query()->findOrFail($agent);

        return response($speed->connectTwiml($row, $user, (string) $request->input('Digits', '')), 200, ['Content-Type' => 'text/xml']);
    }
}
