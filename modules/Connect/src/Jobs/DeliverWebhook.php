<?php

declare(strict_types=1);

namespace Modules\Connect\Jobs;

use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Modules\Connect\Health;
use Modules\Connect\Models\Connection;
use Modules\Connect\Models\WebhookDelivery;
use Modules\Connect\Webhooks\Signature;
use Modules\Core\Support\SafeUrl;

/**
 * Sends one webhook (specs/connect/01): signed JSON, 10 second timeout, no redirects, to the checked IP. A 2xx
 * answer delivers it; otherwise it is tried again after 1 min, 5 min, 30 min, 2 h and 6 h, then marked failed.
 * Never throws (a receiver's problem must not break the action that caused the event).
 */
final class DeliverWebhook implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public const BACKOFF = [60, 300, 1800, 7200, 21600];

    public int $tries = 6;

    public function __construct(public readonly int $deliveryId)
    {
        $this->afterCommit();
    }

    public function handle(): void
    {
        $delivery = WebhookDelivery::query()->find($this->deliveryId);
        $endpoint = $delivery !== null ? Connection::query()->find($delivery->connection_id) : null;
        if ($delivery === null || $delivery->status !== 'pending') {
            return;
        }
        if ($endpoint === null || $endpoint->status === 'paused') {
            $delivery->forceFill(['status' => 'failed', 'last_error' => 'The endpoint was removed or paused.'])->save();

            return;
        }
        $body = (string) json_encode(['id' => $delivery->ulid, 'event' => $delivery->event, 'created_at' => $delivery->created_at->toIso8601String(), 'data' => $delivery->payload],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $attempt = $delivery->attempts + 1;
        $code = null;
        $error = null;
        try {
            $target = SafeUrl::check((string) ($endpoint->settings['url'] ?? ''));
            $response = Http::withOptions(['allow_redirects' => false, 'curl' => [CURLOPT_RESOLVE => [$target['host'].':'.$target['port'].':'.$target['ip']]]])
                ->timeout(10)->connectTimeout(5)
                ->withHeaders(['User-Agent' => 'RightAlly-Webhooks/1.0', 'X-RightAlly-Event' => $delivery->event, 'X-RightAlly-Delivery' => $delivery->ulid,
                    'X-RightAlly-Signature' => Signature::header((string) ($endpoint->credentials['secret'] ?? ''), $body, CarbonImmutable::now()->getTimestamp())])
                ->withBody($body, 'application/json')->post($target['url']);
            $code = $response->status();
            if ($response->successful()) {
                $delivery->forceFill(['status' => 'delivered', 'attempts' => $attempt, 'response_code' => $code, 'last_error' => null, 'delivered_at' => now()])->save();
                app(Health::class)->succeeded($endpoint);

                return;
            }
            $error = 'The endpoint answered '.$code.'.';
        } catch (ValidationException $e) {
            $error = (string) collect($e->errors())->flatten()->first();
            $attempt = $this->tries; // the address itself is not allowed: no point retrying
        } catch (\Throwable $e) {
            $error = mb_substr('Could not reach the endpoint: '.$e->getMessage(), 0, 300);
        }
        $final = $attempt >= $this->tries;
        $delivery->forceFill(['status' => $final ? 'failed' : 'pending', 'attempts' => $attempt, 'response_code' => $code, 'last_error' => $error])->save();
        if ($final) {
            app(Health::class)->failed($endpoint, (string) $error);

            return;
        }
        $this->release(self::BACKOFF[$attempt - 1] ?? 21600);
    }
}
