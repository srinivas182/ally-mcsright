<?php

declare(strict_types=1);

namespace Modules\Crm\Voice;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Spoken AI calls through the voice provider HQ (or the brokerage) chose in Outside services (VO1, specs/grow/14):
 * Retell (recommended), Vapi or Bland. RightAlly starts the call with the facts the AI agent may use (the lead's first
 * name, area, the brokerage, the agent to transfer to and the booking link), then reads the result: how it ended,
 * the transcript and the provider's summary. The AI agent's script, voice and transfer tool live in the provider
 * account; nothing private beyond these facts is sent.
 */
final class VoiceAgents
{
    /**
     * @param  array<string, string>  $keys  api_key, agent_id, from_number
     * @param  array<string, string>  $facts
     * @return string the provider's call id
     */
    public function start(string $provider, array $keys, string $to, array $facts, string $ref): string
    {
        $r = match ($provider) {
            'retell' => $this->http($keys['api_key'])->post('https://api.retellai.com/v2/create-phone-call', ['from_number' => $keys['from_number'], 'to_number' => $to,
                'override_agent_id' => $keys['agent_id'], 'retell_llm_dynamic_variables' => $facts, 'metadata' => ['ref' => $ref]]),
            'vapi' => $this->http($keys['api_key'])->post('https://api.vapi.ai/call', ['assistantId' => $keys['agent_id'], 'phoneNumberId' => $keys['from_number'],
                'customer' => ['number' => $to], 'assistantOverrides' => ['variableValues' => $facts], 'metadata' => ['ref' => $ref]]),
            'bland' => Http::timeout(15)->acceptJson()->withHeaders(['authorization' => $keys['api_key']])->post('https://api.bland.ai/v1/calls', ['phone_number' => $to,
                'from' => $keys['from_number'], 'pathway_id' => $keys['agent_id'], 'request_data' => $facts, 'metadata' => ['ref' => $ref]]),
            default => throw new RuntimeException('Unknown voice provider.'),
        };
        if ($r->failed()) {
            throw new RuntimeException('The voice provider did not start the call ('.$r->status().').');
        }
        $id = (string) ($r->json('call_id') ?? $r->json('id') ?? '');
        if ($id === '') {
            throw new RuntimeException('The voice provider gave no call id.');
        }

        return $id;
    }

    /**
     * How the call went, once it has ended (null while it is still going).
     *
     * @param  array<string, string>  $keys
     * @return array{ended: bool, outcome: string, transcript: string, summary: string, seconds: int}|null
     */
    public function result(string $provider, array $keys, string $callId): ?array
    {
        [$r, $ended, $outcome, $transcript, $summary, $seconds] = match ($provider) {
            'retell' => (function () use ($keys, $callId): array {
                $r = $this->http($keys['api_key'])->get('https://api.retellai.com/v2/get-call/'.rawurlencode($callId));
                $reason = (string) $r->json('disconnection_reason');

                return [$r, in_array((string) $r->json('call_status'), ['ended', 'error'], true), $reason === 'call_transfer' ? 'transferred' : ($reason === 'dial_no_answer' ? 'no_answer' : 'ended'),
                    (string) $r->json('transcript'), (string) $r->json('call_analysis.call_summary'), (int) round(((int) $r->json('duration_ms')) / 1000)];
            })(),
            'vapi' => (function () use ($keys, $callId): array {
                $r = $this->http($keys['api_key'])->get('https://api.vapi.ai/call/'.rawurlencode($callId));
                $reason = (string) $r->json('endedReason');

                return [$r, (string) $r->json('status') === 'ended', str_contains($reason, 'forwarded') ? 'transferred' : (str_contains($reason, 'no-answer') ? 'no_answer' : 'ended'),
                    (string) $r->json('artifact.transcript', (string) $r->json('transcript')), (string) $r->json('analysis.summary'),
                    (int) round((float) $r->json('durationSeconds', 0))];
            })(),
            'bland' => (function () use ($keys, $callId): array {
                $r = Http::timeout(15)->acceptJson()->withHeaders(['authorization' => $keys['api_key']])->get('https://api.bland.ai/v1/calls/'.rawurlencode($callId));

                return [$r, (bool) $r->json('completed'), $r->json('transferred_to') ? 'transferred' : ((string) $r->json('answered_by') === 'no-answer' ? 'no_answer' : 'ended'),
                    (string) $r->json('concatenated_transcript'), (string) $r->json('summary'), (int) round(((float) $r->json('call_length', 0)) * 60)];
            })(),
            default => throw new RuntimeException('Unknown voice provider.'),
        };
        if ($r->failed() || ! $ended) {
            return null;
        }

        return ['ended' => true, 'outcome' => $outcome, 'transcript' => mb_substr($transcript, 0, 100_000), 'summary' => mb_substr($summary, 0, 2000), 'seconds' => $seconds];
    }

    private function http(string $key): PendingRequest
    {
        return Http::timeout(15)->acceptJson()->withToken($key);
    }
}
