<?php

declare(strict_types=1);

namespace Modules\Core\Watchtower\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\Http;
use Modules\Core\Services\OutsideServices;
use Modules\Core\Watchtower\ErrorTracking;
use Throwable;

/** Sends one already-masked problem to the error-tracking service HQ switched on (OB1). Best effort: Watchtower has it. */
final class SendToErrorTracking implements ShouldQueue
{
    use Dispatchable, Queueable;

    public int $tries = 2;

    /** @param array<string, mixed> $payload */
    public function __construct(public readonly string $provider, public readonly array $payload) {}

    public function handle(OutsideServices $services): void
    {
        $active = $services->active(ErrorTracking::SERVICE);
        if ($active === null || $active['provider'] !== $this->provider) {
            return; // switched off or changed since
        }
        $keys = (array) $active['keys'];
        try {
            match ($this->provider) {
                'sentry' => self::sentry((string) ($keys['dsn'] ?? ''), $this->payload),
                'bugsnag' => Http::timeout(10)->withHeaders(['Bugsnag-Api-Key' => (string) ($keys['api_key'] ?? ''), 'Bugsnag-Payload-Version' => '5'])
                    ->post('https://notify.bugsnag.com/', $this->payload + ['apiKey' => (string) ($keys['api_key'] ?? '')]),
                'honeybadger' => Http::timeout(10)->withHeaders(['X-API-Key' => (string) ($keys['api_key'] ?? ''), 'Accept' => 'application/json'])
                    ->post('https://api.honeybadger.io/v1/notices', $this->payload),
                default => null,
            };
        } catch (Throwable) {
            // never let reporting an error cause another
        }
    }

    /** @param array<string, mixed> $event */
    public static function sentry(string $dsn, array $event): bool
    {
        $target = ErrorTracking::sentryDsn($dsn);
        if ($target === null) {
            return false;
        }

        return Http::timeout(10)->withHeaders(['X-Sentry-Auth' => 'Sentry sentry_version=7, sentry_key='.$target['key'].', sentry_client=rightally-watchtower/1.0'])
            ->withBody(ErrorTracking::sentryEnvelope($event), 'application/x-sentry-envelope')->post($target['endpoint'])->successful();
    }
}
