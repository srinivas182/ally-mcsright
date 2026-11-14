<?php

declare(strict_types=1);

namespace Modules\Crm\Http\Controllers\Leads;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Modules\Crm\Leads\LeadIntake;
use Modules\Crm\Leads\LeadSources;

/** POST /api/leads with a source's key (Zapier, lead ads, custom sites). The response never says whether the contact existed. */
final class LeadApiController
{
    public function store(Request $request, LeadIntake $intake): JsonResponse
    {
        $source = LeadSources::forKey((string) $request->bearerToken());
        if ($source === null) {
            return response()->json(['message' => 'Unknown or disabled API key.'], 401);
        }
        $data = $request->validate([
            'first_name' => ['nullable', 'string', 'max:80'], 'last_name' => ['nullable', 'string', 'max:80'], 'name' => ['nullable', 'string', 'max:160'],
            'email' => ['nullable', 'string', 'max:254'], 'phone' => ['nullable', 'string', 'max:30'], 'message' => ['nullable', 'string', 'max:4000'],
            'intent' => ['nullable', 'in:buy,sell,rent'], 'zip' => ['nullable', 'string', 'max:10'], 'property' => ['nullable', 'string', 'max:200'],
            'price' => ['nullable'], 'price_min' => ['nullable'], 'price_max' => ['nullable'], 'source' => ['nullable', 'string', 'max:30'],
            'external_id' => ['nullable', 'string', 'max:100'], 'referrer' => ['nullable', 'string', 'max:500'], 'landing_url' => ['nullable', 'string', 'max:500'],
            'utm_source' => ['nullable', 'string', 'max:150'], 'utm_medium' => ['nullable', 'string', 'max:150'], 'utm_campaign' => ['nullable', 'string', 'max:150'],
            'utm_term' => ['nullable', 'string', 'max:150'], 'utm_content' => ['nullable', 'string', 'max:150'], 'gclid' => ['nullable', 'string', 'max:150'], 'fbclid' => ['nullable', 'string', 'max:150'],
            'sms_consent' => ['nullable', 'boolean'], 'consent_text' => ['nullable', 'string', 'max:500', 'required_if:sms_consent,true'],
        ]);
        try {
            $lead = $intake->receive($source, $data, $request);
        } catch (ValidationException $e) {
            return response()->json(['message' => 'The lead is missing details.', 'errors' => $e->errors()], 422);
        }

        return response()->json(['id' => $lead->ulid, 'received_at' => $lead->created_at->toIso8601String()], $lead->wasRecentlyCreated ? 201 : 200);
    }
}
