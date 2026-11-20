<?php

declare(strict_types=1);

namespace Modules\Core\Watchtower;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Modules\Core\Services\OutsideServices;
use Modules\Core\Watchtower\Jobs\SendToErrorTracking;

/**
 * OB1: problems also go to an error-tracking service when HQ switches one on in HQ › Outside services › Error tracking
 * (Sentry recommended; Bugsnag or Honeybadger). Off sends nothing; Test sends with the environment "test"; Live sends
 * as the server's environment. Only what Watchtower keeps is sent — already masked (no personal data, keys or tokens) —
 * at most once a minute per problem, from the queue, so a page never waits for the service.
 */
final readonly class ErrorTracking
{
    public const SERVICE = 'error_tracking';

    public function __construct(private OutsideServices $services) {}

    public function forward(Signal $s): void
    {
        $active = $this->services->active(self::SERVICE);
        if ($active === null || ! in_array($active['provider'], ['sentry', 'bugsnag', 'honeybadger'], true)) {
            return;
        }
        if (! Cache::add('watchtower:tracking:'.$s->fingerprint(), 1, 60)) {
            return; // the same problem again within the minute: Watchtower counts it, the service needn't
        }
        $environment = $active['mode'] === 'live' ? (string) config('app.env') : 'test';
        SendToErrorTracking::dispatch((string) $active['provider'], self::payload((string) $active['provider'], $s, $environment));
    }

    /**
     * What the provider's own API expects, built only from the masked signal.
     *
     * @return array<string, mixed>
     */
    public static function payload(string $provider, Signal $s, string $environment): array
    {
        $frames = [];
        foreach (array_slice(explode("\n", (string) $s->trace), 0, 25) as $line) {
            if (preg_match('/^(?:#\d+\s+)?([^\s:]+):(\d+|\?)(?:\s+(.+?)\(\))?$/', trim($line), $m) === 1) {
                $frames[] = ['file' => $m[1], 'line' => (int) $m[2], 'function' => $m[3] ?? null, 'in_app' => ! str_contains($m[1], 'vendor/')];
            }
        }
        $type = $s->exceptionClass ?? (Incident::KINDS[$s->kind] ?? $s->kind);
        $value = mb_substr((string) ($s->message !== null && $s->message !== '' ? $s->message : $s->title), 0, 1000);
        $release = 'rightally@'.Release::current();
        $level = in_array($s->severity, ['critical', 'high'], true) ? 'error' : 'warning';

        return match ($provider) {
            'bugsnag' => ['payloadVersion' => '5', 'notifier' => ['name' => 'RightAlly Watchtower', 'version' => '1.0', 'url' => 'https://rightally.io'],
                'events' => [['exceptions' => [['errorClass' => $type, 'message' => $value,
                    'stacktrace' => array_map(fn (array $f): array => ['file' => $f['file'], 'lineNumber' => $f['line'], 'method' => (string) $f['function'], 'inProject' => $f['in_app']], $frames)]],
                    'severity' => $level, 'context' => $s->location, 'groupingHash' => $s->fingerprint(), 'unhandled' => true,
                    'app' => ['releaseStage' => $environment, 'version' => Release::current()], 'metaData' => ['watchtower' => ['kind' => $s->kind, 'surface' => $s->surface]]]]],
            'honeybadger' => ['notifier' => ['name' => 'RightAlly Watchtower', 'version' => '1.0', 'url' => 'https://rightally.io'],
                'error' => ['class' => $type, 'message' => $value, 'fingerprint' => $s->fingerprint(), 'tags' => array_values(array_filter([$s->kind, $s->surface])),
                    'backtrace' => array_map(fn (array $f): array => ['file' => $f['file'], 'number' => (string) $f['line'], 'method' => (string) $f['function']], $frames)],
                'request' => ['component' => $s->surface, 'action' => $s->location, 'context' => []],
                'server' => ['environment_name' => $environment, 'revision' => Release::commit() ?? Release::current()]],
            default => ['event_id' => str_replace('-', '', (string) Str::uuid()), 'timestamp' => gmdate('Y-m-d\TH:i:s\Z'), 'platform' => 'php', 'level' => $level,
                'environment' => mb_substr($environment, 0, 64), 'release' => $release, 'fingerprint' => [$s->fingerprint()],
                'tags' => array_filter(['kind' => $s->kind, 'surface' => $s->surface, 'location' => $s->location !== null ? mb_substr($s->location, 0, 200) : null]),
                'exception' => ['values' => [array_filter(['type' => $type, 'value' => $value,
                    'stacktrace' => $frames === [] ? null : ['frames' => array_reverse(array_map(fn (array $f): array => ['filename' => $f['file'], 'lineno' => $f['line'],
                        'function' => $f['function'], 'in_app' => $f['in_app']], $frames))]])]]],
        };
    }

    /**
     * A Sentry DSN (https://<public key>@<host>/<project id>) split into where events go and how they are signed.
     *
     * @return array{endpoint: string, key: string}|null
     */
    public static function sentryDsn(string $dsn): ?array
    {
        $p = parse_url(trim($dsn));
        $project = trim((string) ($p['path'] ?? ''), '/');
        if (! is_array($p) || ($p['scheme'] ?? '') !== 'https' || ($p['user'] ?? '') === '' || ($p['host'] ?? '') === '' || preg_match('/^\d+$/', $project) !== 1) {
            return null;
        }

        return ['endpoint' => 'https://'.$p['host'].(isset($p['port']) ? ':'.$p['port'] : '').'/api/'.$project.'/envelope/', 'key' => (string) $p['user']];
    }

    /** @param array<string, mixed> $event */
    public static function sentryEnvelope(array $event): string
    {
        return json_encode(['event_id' => $event['event_id'] ?? '', 'sent_at' => gmdate('Y-m-d\TH:i:s\Z')], JSON_UNESCAPED_SLASHES)."\n"
            .json_encode(['type' => 'event'])."\n".json_encode($event, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
    }
}
