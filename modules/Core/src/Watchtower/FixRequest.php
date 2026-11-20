<?php

declare(strict_types=1);

namespace Modules\Core\Watchtower;

/**
 * The "fix request" (OB1): one self-contained report about a problem, written to be pasted to the engineering team as
 * it is: what broke, where, since which release, how often, the AI's root cause and fix when there is one, and the
 * trace. Personal data was removed when the problem was recorded.
 */
final class FixRequest
{
    public static function for(Incident $i): string
    {
        $tz = 'America/Chicago';
        $days = Settings::db()->table('watchtower_incident_days')->where('incident_id', $i->id)->where('day', '>=', now()->subDays(6)->toDateString())->orderBy('day')
            ->pluck('count', 'day')->all();
        $lines = [
            "## RightAlly fix request: {$i->reference()}",
            '',
            "**Problem:** {$i->title}",
            '**Kind:** '.(Incident::KINDS[$i->kind] ?? $i->kind).' · **Severity:** '.(Incident::SEVERITIES[$i->severity] ?? $i->severity).' · **Status:** '.(Incident::STATUSES[$i->status] ?? $i->status)
                .($i->regressed_at !== null && $i->status === 'new' ? ' · **came back after being marked fixed'.($i->fixed_in !== null ? " in {$i->fixed_in}" : '').'**' : ''),
            "**Release:** first seen in {$i->first_release}, last seen in {$i->last_release}".(Release::commit() !== null ? ' (running commit '.Release::commit().')' : ''),
        ];
        if ($i->location !== null) {
            $lines[] = "**Where:** {$i->location}".($i->surface !== null ? " ({$i->surface})" : '');
        }
        if ($i->file !== null && $i->file !== '') {
            $lines[] = "**In our code at:** `{$i->file}:{$i->line}`";
        }
        $lines[] = "**How often:** {$i->occurrences} time".($i->occurrences === 1 ? '' : 's').' since '.$i->first_seen_at->copy()->setTimezone($tz)->format('M j, Y g:i A')
            .' Central; '.$i->lastHour().' in the last hour'.($i->brokerages > 0 ? "; {$i->brokerages} brokerage".($i->brokerages === 1 ? '' : 's').' affected' : '')
            .'; last at '.$i->last_seen_at->copy()->setTimezone($tz)->format('M j, Y g:i A').' Central';
        if ($days !== []) {
            $lines[] = '**Last 7 days:** '.implode(', ', array_map(fn ($d, $n): string => date('D M j', (int) strtotime((string) $d)).": {$n}", array_keys($days), $days));
        }
        if ($i->exception_class !== null) {
            $lines[] = '';
            $lines[] = "**Error:** `{$i->exception_class}`";
            if ($i->message !== null && $i->message !== '') {
                $lines[] = '> '.str_replace("\n", "\n> ", $i->message);
            }
        } elseif ($i->message !== null && $i->message !== '') {
            $lines[] = '';
            $lines[] = '**Details:** '.$i->message;
        }
        $context = array_filter((array) $i->context, fn ($v): bool => $v !== null && $v !== '' && $v !== []);
        if ($context !== []) {
            $lines[] = '';
            $lines[] = '**Context:** '.implode(' · ', array_map(fn ($k, $v): string => "{$k}: ".(is_scalar($v) ? (string) $v : (string) json_encode($v)), array_keys($context), $context));
        }
        $analysis = (array) $i->analysis;
        if (($analysis['summary'] ?? '') !== '') {
            $lines = [...$lines, '', '### Root cause (from Watchtower\'s AI, confidence: '.($analysis['confidence'] ?? 'low').')', (string) $analysis['summary']];
            foreach (['cause' => 'Likely cause', 'fix' => 'Suggested fix'] as $k => $label) {
                if (($analysis[$k] ?? '') !== '') {
                    $lines[] = "**{$label}:** ".$analysis[$k];
                }
            }
        }
        if ($i->trace !== null && $i->trace !== '') {
            $lines = [...$lines, '', '### Stack trace (top frames, arguments removed)', '```', $i->trace, '```'];
        }

        return implode("\n", [...$lines, '', 'Please find the root cause and fix it in the RightAlly repository, with a test that fails before the fix. Personal data has been removed from this report.']);
    }
}
