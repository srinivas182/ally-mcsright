<?php

declare(strict_types=1);

namespace Modules\Core\Watchtower;

use Modules\AI\AiGateway;
use Modules\AI\Data\AiTask;
use Modules\Core\Tenancy\Models\Tenant;
use Modules\Core\Tenancy\TenantContext;
use Throwable;

/**
 * Watchtower's AI engineer (OB1). For a problem worth explaining it reads the error, the trace, the lines of
 * RightAlly's own code where it happened (from the release that is running), and earlier problems in the same file,
 * then writes a plain summary, the likely root cause, a suggested fix and how serious it is. It can raise severity,
 * never lower it. Its words go into the email and the fix request; it never changes anything itself.
 *
 * Paid by HQ (never a brokerage): one call per problem, within HQ's daily limit. HQ can switch it off.
 */
final readonly class Analyst
{
    public const FEATURE = 'hq.watchtower';

    public const PROMPT = 'watchtower.explain';

    /** Lines either side of each line of code shown to the AI. */
    public const CONTEXT_LINES = 12;

    public const MAX_FRAMES = 3;

    public const SCHEMA = ['type' => 'object', 'required' => ['summary', 'cause', 'fix', 'severity', 'confidence'], 'properties' => [
        'summary' => ['type' => 'string'], 'cause' => ['type' => 'string'], 'fix' => ['type' => 'string'],
        'severity' => ['type' => 'string', 'enum' => ['critical', 'high', 'normal']], 'confidence' => ['type' => 'string', 'enum' => ['high', 'medium', 'low']],
    ]];

    public function __construct(private AiGateway $ai, private TenantContext $tenants) {}

    /** Should this problem be explained? (critical or high, back after a fix, or a normal one that keeps happening) */
    public static function worthIt(Incident $i): bool
    {
        if ($i->status === 'ignored' || in_array($i->kind, ['check', 'queue', 'downtime'], true)) {
            return false; // the check already says what is wrong
        }
        $done = (array) $i->analysis;
        $at = is_string($done['at'] ?? null) ? strtotime($done['at']) : false;
        if (in_array($done['status'] ?? null, ['done', 'failed'], true) && ($done['release'] ?? null) === $i->last_release
            && ($i->regressed_at === null || ($at !== false && $at >= $i->regressed_at->getTimestamp()))) {
            return false; // already explained for this release (and since it came back)
        }

        return Incident::rank($i->severity) >= Incident::rank('high') || $i->regressed_at !== null || $i->occurrences >= 5;
    }

    public static function underCap(): bool
    {
        return self::today() < Settings::all()['ai_daily_cap'];
    }

    public static function today(): int
    {
        return Incident::query()->whereDate('analysed_on', now()->toDateString())->count();
    }

    /**
     * Explain the problem; saves and returns the analysis (status "failed" when the AI could not).
     *
     * @return array<string, mixed>
     */
    public function analyse(Incident $i): array
    {
        $now = now();
        try {
            $task = new AiTask(self::FEATURE, self::PROMPT, 'watchtower:'.$i->id.':'.$i->last_release.':'.$now->format('YmdHi'),
                untrustedContent: [$this->incident($i), self::code($i), $this->earlier($i)], outputSchema: self::SCHEMA);
            $hq = Tenant::query()->where('type', 'hq')->first();
            $result = $hq !== null ? $this->tenants->runAs($hq, fn () => $this->ai->run($task)) : $this->ai->run($task);
            $data = (array) ($result->json ?? []);
            $summary = is_string($data['summary'] ?? null) ? trim($data['summary']) : '';
            if ($summary === '') {
                throw new \RuntimeException('The AI answer could not be read.');
            }
            $analysis = [
                'status' => 'done',
                'summary' => mb_substr($summary, 0, 1500),
                'cause' => mb_substr(trim((string) ($data['cause'] ?? '')), 0, 1500),
                'fix' => mb_substr(trim((string) ($data['fix'] ?? '')), 0, 2500),
                'severity' => in_array($data['severity'] ?? null, ['critical', 'high', 'normal'], true) ? $data['severity'] : null,
                'confidence' => in_array($data['confidence'] ?? null, ['high', 'medium', 'low'], true) ? $data['confidence'] : 'low',
                'release' => $i->last_release,
                'at' => $now->toIso8601String(),
            ];
            $severity = Severity::max($i->severity, $analysis['severity']); // raise only
        } catch (Throwable $e) {
            $analysis = ['status' => 'failed', 'error' => 'The AI did not answer: '.Mask::text($e->getMessage(), 160), 'release' => $i->last_release, 'at' => $now->toIso8601String()];
            $severity = $i->severity;
        }
        $i->forceFill(['analysis' => $analysis, 'severity' => $severity, 'analysed_on' => $now->toDateString()])->save();

        return $analysis;
    }

    private function incident(Incident $i): string
    {
        $lines = ["PROBLEM {$i->reference()}", 'Kind: '.(Incident::KINDS[$i->kind] ?? $i->kind), "Title: {$i->title}"];
        foreach (['Error class' => $i->exception_class, 'Message' => $i->message, 'Where' => $i->location, 'Part of RightAlly' => $i->surface,
            'Origin' => $i->file !== null ? "{$i->file}:{$i->line}" : null] as $k => $v) {
            if ($v !== null && $v !== '') {
                $lines[] = "{$k}: {$v}";
            }
        }
        $lines[] = "Happened {$i->occurrences} times ({$i->lastHour()} in the last hour), at {$i->brokerages} brokerages, first in release {$i->first_release}, last in {$i->last_release}.";
        if ($i->regressed_at !== null) {
            $lines[] = 'It was marked fixed'.($i->fixed_in !== null ? " in {$i->fixed_in}" : '').' and has come back.';
        }
        if ($i->context !== null && $i->context !== []) {
            $lines[] = 'Context: '.json_encode($i->context);
        }
        if ($i->trace !== null && $i->trace !== '') {
            $lines[] = "Trace:\n".mb_substr($i->trace, 0, 4000);
        }

        return implode("\n", $lines);
    }

    private function earlier(Incident $i): string
    {
        $earlier = Incident::query()->where('id', '!=', $i->id)->whereNotNull('file')->where('file', $i->file)->orderByDesc('last_seen_at')->limit(5)
            ->get(['title', 'status', 'fixed_in']);
        if ($earlier->isEmpty()) {
            return 'EARLIER PROBLEMS IN THE SAME FILE: none';
        }

        return "EARLIER PROBLEMS IN THE SAME FILE:\n".$earlier->map(fn (Incident $e): string => "- {$e->title} ({$e->status}".($e->fixed_in !== null ? ", fixed in {$e->fixed_in}" : '').')')->implode("\n");
    }

    /** The lines around the first few places in RightAlly's own PHP code (never vendor/, never outside the app). */
    public static function code(Incident $i): string
    {
        $places = [];
        if ($i->file !== null && $i->line !== null) {
            $places[] = [$i->file, $i->line];
        }
        foreach (explode("\n", (string) $i->trace) as $l) {
            if (preg_match('#\s((?:modules|app)/[\w/\-.]+\.php):(\d+)#', $l, $m) === 1) {
                $places[] = [$m[1], (int) $m[2]];
            }
        }
        $out = [];
        $seen = [];
        foreach ($places as [$file, $line]) {
            if (count($out) >= self::MAX_FRAMES || isset($seen[$file.':'.$line]) || preg_match('#^(modules|app)/[\w/\-.]+\.php$#', $file) !== 1 || str_contains($file, '..')) {
                continue;
            }
            $seen[$file.':'.$line] = true;
            $path = base_path($file);
            $all = is_file($path) ? (file($path) ?: []) : [];
            if ($all === []) {
                continue;
            }
            $from = max(1, $line - self::CONTEXT_LINES);
            $to = min(count($all), $line + self::CONTEXT_LINES);
            $chunk = [];
            for ($n = $from; $n <= $to; $n++) {
                $chunk[] = ($n === $line ? '>' : ' ').str_pad((string) $n, 5, ' ', STR_PAD_LEFT).'  '.rtrim($all[$n - 1]);
            }
            $out[] = "--- {$file}\n".implode("\n", $chunk);
        }

        return $out === [] ? 'CODE: not available' : "CODE (the running release):\n".implode("\n\n", $out);
    }
}
