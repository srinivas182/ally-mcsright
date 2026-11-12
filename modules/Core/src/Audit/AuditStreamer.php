<?php

declare(strict_types=1);

namespace Modules\Core\Audit;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Modules\Core\Identity\Enums\UserKind;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\TenantContext;
use RuntimeException;
use Throwable;

/**
 * Streams sealed audit events to the brokerage's own security tools (specs/core/04, addition 1).
 *
 * - In order per brokerage, from a saved position: a failure retries from the same event, so
 *   nothing is skipped (delivery is at least once; every event carries its ULID for de-duplication).
 * - Webhooks are signed: X-RightAlly-Signature: t=<unix>,v1=HMAC-SHA256(secret, "<t>.<body>").
 * - The owner is alerted when a destination starts failing; it pauses after 24 hours of failures.
 */
final class AuditStreamer
{
    public const BATCH = 500;

    public function __construct(private readonly Http $http, private readonly TenantContext $tenants) {}

    /**
     * @param  array<string, string>  $config
     */
    public function add(string $type, string $name, array $config, User $by): AuditStream
    {
        if (! in_array($type, AuditStream::TYPES, true)) {
            throw ValidationException::withMessages(['type' => 'Choose a webhook, Amazon S3, Datadog or Splunk.']);
        }
        $config = $this->validate($type, $config);
        $latest = (int) DB::connection(Connections::cell())->table('audit_events')->where('tenant_id', $this->tenants->id())->max('chain_seq');

        $stream = AuditStream::query()->create([
            'type' => $type, 'name' => mb_substr(trim($name), 0, 80) ?: ucfirst($type), 'config' => $config, 'created_by' => $by->id,
            'cursor_seq' => $latest, // start from now; history can be exported separately
        ]);
        app(AuditLogger::class)->record('core.audit.stream_added', $stream, metadata: ['type' => $type, 'name' => $stream->name], severity: 'warning', label: $stream->name);

        return $stream;
    }

    public function remove(AuditStream $stream): void
    {
        app(AuditLogger::class)->record('core.audit.stream_removed', $stream, metadata: ['type' => $stream->type, 'name' => $stream->name], severity: 'warning', label: $stream->name);
        $stream->delete();
    }

    public function resume(AuditStream $stream): void
    {
        $stream->update(['status' => 'active', 'failing_since' => null, 'last_error' => null]);
        app(AuditLogger::class)->record('core.audit.stream_resumed', $stream, severity: 'notice', label: $stream->name);
    }

    /**
     * Sends one test event (not from the log) so the owner can check the destination.
     */
    public function test(AuditStream $stream): void
    {
        $this->send($stream, [[
            'id' => 'test-'.CarbonImmutable::now()->format('YmdHis'), 'seq' => 0, 'time' => CarbonImmutable::now()->toIso8601String(),
            'brokerage' => $this->tenants->current()->slug, 'action' => 'core.audit.stream_test', 'severity' => 'info',
            'actor' => ['type' => 'system'], 'subject' => null, 'changes' => null, 'metadata' => ['message' => 'Test event from RightAlly'],
        ]]);
    }

    /**
     * Delivers waiting events for every active destination of the current brokerage.
     */
    public function deliverAll(): int
    {
        $sent = 0;
        foreach (AuditStream::query()->where('status', 'active')->get() as $stream) {
            $sent += $this->deliver($stream);
        }

        return $sent;
    }

    public function deliver(AuditStream $stream): int
    {
        $rows = DB::connection(Connections::cell())->table('audit_events')->where('tenant_id', $stream->tenant_id)
            ->where('chain_seq', '>', $stream->cursor_seq)->orderBy('chain_seq')->limit(self::BATCH)->get();
        if ($rows->isEmpty()) {
            return 0;
        }

        $events = array_values($rows->map(fn ($r): array => $this->schema((array) $r))->all());
        try {
            $this->send($stream, $events);
        } catch (Throwable $e) {
            $this->failed($stream, $e);

            return 0;
        }

        $stream->update(['cursor_seq' => (int) $rows->last()->chain_seq, 'last_success_at' => CarbonImmutable::now(), 'last_error' => null, 'failing_since' => null]);

        return count($events);
    }

    /**
     * @param  list<array<string, mixed>>  $events
     */
    private function send(AuditStream $stream, array $events): void
    {
        $c = $stream->config;
        $body = (string) json_encode(['events' => $events], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if ($stream->type === 's3') {
            $disk = Storage::build(['driver' => 's3', 'key' => $c['key'], 'secret' => $c['secret'], 'region' => $c['region'], 'bucket' => $c['bucket'], 'throw' => true]);
            $first = $events[0]['seq'];
            $last = $events[count($events) - 1]['seq'];
            $path = trim($c['prefix'] ?? 'rightally-audit', '/').'/'.CarbonImmutable::now()->format('Y/m/d/H')."/{$first}-{$last}.jsonl";
            $disk->put($path, implode("\n", array_map(fn ($e) => json_encode($e, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $events))."\n");

            return;
        }

        $response = match ($stream->type) {
            'webhook' => $this->http->timeout(15)->withHeaders(['X-RightAlly-Signature' => $this->sign($c['secret'], $body)])
                ->withBody($body, 'application/json')->post($c['url']),
            'datadog' => $this->http->timeout(15)->withHeaders(['DD-API-KEY' => $c['api_key']])
                ->post('https://http-intake.logs.'.($c['site'] ?? 'datadoghq.com').'/api/v2/logs', array_map(fn ($e) => [
                    'ddsource' => 'rightally', 'service' => 'rightally-audit', 'hostname' => $e['brokerage'], 'message' => $e['action'], 'audit' => $e,
                ], $events)),
            'splunk' => $this->http->timeout(15)->withHeaders(['Authorization' => 'Splunk '.$c['token']])
                ->withBody(implode('', array_map(fn ($e) => json_encode(['sourcetype' => 'rightally:audit', 'event' => $e]), $events)), 'application/json')
                ->post(rtrim($c['url'], '/').'/services/collector/event'),
            default => throw new RuntimeException('Unknown destination.'),
        };

        if ($response->failed()) {
            throw new RuntimeException("{$stream->name} answered HTTP {$response->status()}.");
        }
    }

    public function sign(string $secret, string $body, ?int $time = null): string
    {
        $t = $time ?? CarbonImmutable::now()->getTimestamp();

        return 't='.$t.',v1='.hash_hmac('sha256', $t.'.'.$body, $secret);
    }

    private function failed(AuditStream $stream, Throwable $e): void
    {
        $now = CarbonImmutable::now();
        $first = $stream->failing_since === null;
        $since = $stream->failing_since ?? $now;
        $pause = $since->lte($now->subDay());

        $stream->update(['last_error' => mb_substr($e->getMessage(), 0, 300), 'failing_since' => $since, 'status' => $pause ? 'paused' : 'active']);

        if ($first || $pause) {
            foreach (User::query()->where('kind', UserKind::Owner)->where('status', 'active')->get() as $owner) {
                $owner->notify(new AuditStreamFailing($stream->name, $stream->last_error ?? '', $pause));
            }
            app(AuditLogger::class)->record($pause ? 'core.audit.stream_paused' : 'core.audit.stream_failing', $stream, metadata: ['error' => $stream->last_error], severity: 'warning', label: $stream->name);
        }
    }

    /**
     * @param  array<string, mixed>  $r
     * @return array<string, mixed>
     */
    private function schema(array $r): array
    {
        return [
            'id' => $r['ulid'], 'seq' => (int) $r['chain_seq'], 'time' => CarbonImmutable::parse((string) $r['occurred_at'])->toIso8601String(),
            'brokerage' => $this->tenants->current()->slug, 'action' => $r['action'], 'severity' => $r['severity'],
            'actor' => ['type' => $r['actor_type'], 'id' => $r['actor_id'], 'name' => $r['actor_name'], 'kind' => $r['actor_kind']],
            'impersonator' => $r['impersonator_id'] !== null ? ['id' => $r['impersonator_id'], 'name' => $r['impersonator_name']] : null,
            'automation' => $r['automation'] !== null ? ['name' => $r['automation'], 'on_behalf_of' => $r['on_behalf_of_user_id'], 'scope' => $r['automation_scope']] : null,
            'subject' => $r['subject_type'] !== null ? ['type' => $r['subject_type'], 'id' => $r['subject_id'], 'label' => $r['subject_label']] : null,
            'changes' => $r['changes'] !== null ? json_decode((string) $r['changes'], true) : null,
            'metadata' => $r['metadata'] !== null ? json_decode((string) $r['metadata'], true) : null,
            'ip' => $r['ip'], 'request_id' => $r['request_id'], 'hash' => $r['hash'], 'prev_hash' => $r['prev_hash'],
        ];
    }

    /**
     * @param  array<string, string>  $config
     * @return array<string, string>
     */
    private function validate(string $type, array $config): array
    {
        $required = ['webhook' => ['url', 'secret'], 's3' => ['bucket', 'region', 'key', 'secret'], 'datadog' => ['api_key'], 'splunk' => ['url', 'token']][$type];
        foreach ($required as $field) {
            if (trim((string) ($config[$field] ?? '')) === '') {
                throw ValidationException::withMessages(["config.{$field}" => 'This is required.']);
            }
        }
        foreach (['url'] as $field) {
            if (isset($config[$field]) && ! str_starts_with((string) $config[$field], 'https://')) {
                throw ValidationException::withMessages(["config.{$field}" => 'Use an https:// address.']);
            }
        }
        if ($type === 'webhook' && mb_strlen((string) $config['secret']) < 24) {
            throw ValidationException::withMessages(['config.secret' => 'Use a signing secret of at least 24 characters.']);
        }

        return array_map(fn ($v) => trim((string) $v), array_intersect_key($config, array_flip([...$required, 'prefix', 'site'])));
    }
}
