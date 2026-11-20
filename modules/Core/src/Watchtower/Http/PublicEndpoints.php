<?php

declare(strict_types=1);

namespace Modules\Core\Watchtower\Http;

use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Modules\Core\Watchtower\Heartbeats;
use Modules\Core\Watchtower\Mask;
use Modules\Core\Watchtower\Recorder;
use Modules\Core\Watchtower\Settings;
use Modules\Core\Watchtower\Signal;
use Modules\Core\Watchtower\Uptime;
use Modules\Core\Watchtower\Where;
use Throwable;

/**
 * Watchtower's three outside doors (OB1), none of which uses a session:
 * - GET /up/scheduler, for the uptime checker: 503 when the scheduler has stopped (Watchtower's own every-minute check
 *   has not run for 5 minutes), the one failure Watchtower cannot email about itself.
 * - POST /webhooks/uptime/{token}: the uptime checker says an outage started or ended (Better Stack, UptimeRobot, or a
 *   plain {monitor, status, cause}). Without a token made in HQ › Watchtower the address does not exist.
 * - POST /watchtower/browser-error: a crash in someone's browser, rate-limited and masked like everything else.
 */
final class PublicEndpoints
{
    public const STALE_MINUTES = 5;

    public function scheduler(): JsonResponse
    {
        $last = Heartbeats::pulse();
        $ok = $last !== null && CarbonImmutable::parse($last, (string) config('app.timezone'))->greaterThan(now()->subMinutes(self::STALE_MINUTES));

        return new JsonResponse(['ok' => $ok, 'scheduler' => $ok ? 'running' : ($last === null ? 'never ran' : 'stopped')], $ok ? 200 : 503, ['Cache-Control' => 'no-store']);
    }

    public function uptime(Request $request, string $token, Uptime $uptime): Response
    {
        $expected = Settings::uptimeToken();
        abort_if($expected === '' || ! hash_equals($expected, $token), 404);
        [$monitor, $state, $cause, $at] = self::parseUptime($request);
        abort_if($monitor === '' || $state === null, 422, 'Not an uptime alert.');
        $state === 'down' ? $uptime->down($monitor, $cause, $at) : $uptime->up($monitor, $at);

        return response()->noContent();
    }

    public function browserError(Request $request, Recorder $recorder): Response
    {
        if (! Settings::on('browser_errors')) {
            return response()->noContent();
        }
        $d = $request->validate([
            'message' => ['required', 'string', 'max:1000'],
            'stack' => ['nullable', 'string', 'max:8000'],
            'page' => ['nullable', 'string', 'max:120'],
            'kind' => ['nullable', 'in:error,rejection,render'],
        ]);
        $stack = Mask::text(self::stripBuild((string) ($d['stack'] ?? '')), 3000);
        $frame = '';
        foreach (explode("\n", $stack) as $l) {
            if (preg_match('#(/build/assets/[^\s):]+:\d+)#', $l, $m) === 1) {
                $frame = $m[1];
                break;
            }
        }
        $page = preg_replace('/[^A-Za-z0-9_:\/.\-]/', '', (string) ($d['page'] ?? '')) ?: null;
        $message = Mask::text((string) $d['message'], 500);
        $recorder->record(new Signal(
            kind: 'browser',
            group: Mask::normalise((string) $d['message']).'|'.$frame.'|'.$page,
            title: mb_substr('Browser: '.$message, 0, 250),
            message: $message,
            file: $frame !== '' ? $frame : null,
            location: $page !== null ? "page {$page}" : null,
            surface: Where::surface($request),
            trace: $stack !== '' ? $stack : null,
            context: ['kind' => $d['kind'] ?? 'error', 'browser' => self::browser((string) $request->userAgent())],
        ));

        return response()->noContent();
    }

    /** @return array{0: string, 1: 'down'|'up'|null, 2: string|null, 3: CarbonImmutable} */
    public static function parseUptime(Request $request): array
    {
        // Better Stack: {"data": {"attributes": {"name", "url", "cause", "started_at", "resolved_at", "status"}}}
        $a = $request->input('data.attributes');
        if (is_array($a)) {
            $resolved = ($a['resolved_at'] ?? null) !== null || strtolower((string) ($a['status'] ?? '')) === 'resolved';

            return [(string) ($a['name'] ?? $a['url'] ?? ''), $resolved ? 'up' : 'down', is_string($a['cause'] ?? null) ? $a['cause'] : null,
                self::time($resolved ? ($a['resolved_at'] ?? null) : ($a['started_at'] ?? null))];
        }
        // UptimeRobot: monitorFriendlyName, alertType (1 = down, 2 = up), alertDetails, alertDateTime (unix seconds)
        if ($request->has('alertType')) {
            $type = (string) $request->input('alertType');

            return [(string) $request->input('monitorFriendlyName', $request->input('monitorURL', '')), $type === '1' ? 'down' : ($type === '2' ? 'up' : null),
                $request->filled('alertDetails') ? (string) $request->input('alertDetails') : null, self::time($request->input('alertDateTime'))];
        }
        $status = strtolower((string) $request->input('status'));

        return [(string) $request->input('monitor', ''), in_array($status, ['down', 'up'], true) ? $status : null,
            $request->filled('cause') ? (string) $request->input('cause') : null, self::time($request->input('at'))];
    }

    /** "app-3f9a1c2b.js" is "app.js": the same line in a new build is the same bug. Hosts and query strings removed. */
    public static function stripBuild(string $stack): string
    {
        $stack = (string) preg_replace('#https?://[^/\s]+#', '', $stack);
        $stack = (string) preg_replace('#\?[^\s:)]*#', '', $stack);

        return (string) preg_replace('#([A-Za-z0-9_]+)-[A-Za-z0-9_]{8}\.(js|css)#', '$1.$2', $stack);
    }

    private static function time(mixed $value): CarbonImmutable
    {
        try {
            if (is_numeric($value)) {
                return CarbonImmutable::createFromTimestamp((int) $value)->setTimezone((string) config('app.timezone'));
            }
            if (is_string($value) && $value !== '') {
                $t = CarbonImmutable::parse($value)->setTimezone((string) config('app.timezone'));

                return $t->greaterThan(now()->addMinutes(5)) ? CarbonImmutable::now() : $t; // never in the future
            }
        } catch (Throwable) {
            // fall through
        }

        return CarbonImmutable::now();
    }

    private static function browser(string $ua): string
    {
        return match (true) {
            str_contains($ua, 'Edg/') => 'Edge',
            str_contains($ua, 'Chrome/') => 'Chrome',
            str_contains($ua, 'Firefox/') => 'Firefox',
            str_contains($ua, 'Safari/') => 'Safari',
            default => 'Other',
        }.(preg_match('/Mobile|Android|iPhone/', $ua) === 1 ? ' (mobile)' : '');
    }
}
