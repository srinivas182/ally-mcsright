<?php

declare(strict_types=1);

namespace Modules\Sites\Ailly;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Core\Audit\AuditLogger;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\TenantContext;
use Modules\Crm\FairHousing;
use Modules\Sites\Models\Site;

/**
 * A/B testing AIlly's greeting and when it asks for details (specs/websites/14). Half of new chats (by visitor,
 * so a returning visitor keeps the same version) see version B; the results compare how many chats became leads and
 * bookings, with a plain-English verdict from a two-proportion test (95%). "Use B" makes B the setting for everyone.
 *
 * Kept apart from the main settings ("ailly_test" on the brokerage website), so saving settings never ends a test.
 */
final class Experiments
{
    /** Chats per version before a verdict is offered. */
    public const MIN_CHATS = 100;

    public function __construct(private readonly TenantContext $tenants) {}

    /**
     * @return array{on: bool, greeting_b: string, gate_b: string|null, started_at: string|null}
     */
    public static function for(?Site $brokerage): array
    {
        $t = (array) (($brokerage !== null ? ($brokerage->settings ?? []) : [])['ailly_test'] ?? []);

        return ['on' => (bool) ($t['on'] ?? false), 'greeting_b' => (string) ($t['greeting_b'] ?? ''),
            'gate_b' => in_array($t['gate_b'] ?? null, ['before', 'after', 'booking'], true) ? (string) $t['gate_b'] : null,
            'started_at' => is_string($t['started_at'] ?? null) ? $t['started_at'] : null];
    }

    /** Starts (or restarts) a test of version B against the current settings. */
    public function start(Site $brokerage, string $greeting, ?string $gate, User $by): void
    {
        $greeting = mb_substr(trim(strip_tags($greeting)), 0, 240);
        $gate = in_array($gate, ['before', 'after', 'booking'], true) ? $gate : null;
        $current = AillySettings::for($brokerage);
        if ($greeting === '' && $gate === null) {
            throw ValidationException::withMessages(['greeting_b' => 'Change the greeting, when AIlly asks for details, or both.']);
        }
        if ($greeting === AillySettings::greeting($current, $current['name']) && ($gate === null || $gate === $current['gate'])) {
            throw ValidationException::withMessages(['greeting_b' => 'Version B is the same as what visitors see now.']);
        }
        if (($flags = FairHousing::flags($greeting)) !== []) {
            throw ValidationException::withMessages(['greeting_b' => 'Fair Housing: reword "'.implode('", "', $flags).'".']);
        }
        $this->store($brokerage, ['on' => true, 'greeting_b' => $greeting, 'gate_b' => $gate, 'started_at' => CarbonImmutable::now()->toIso8601String()]);
        app(AuditLogger::class)->record('sites.ailly.test_started', $brokerage, metadata: ['gate_b' => $gate], severity: 'notice');
    }

    public function stop(Site $brokerage, bool $useB, User $by): void
    {
        $t = self::for($brokerage);
        if (! $t['on']) {
            return;
        }
        if ($useB) {
            $s = AillySettings::for($brokerage);
            AillySettings::save($brokerage, [...$s, 'greeting' => $t['greeting_b'] !== '' ? $t['greeting_b'] : $s['greeting'], 'gate' => $t['gate_b'] ?? $s['gate']], $by);
            $brokerage->refresh();
        }
        $this->store($brokerage, [...$t, 'on' => false]);
        app(AuditLogger::class)->record('sites.ailly.test_ended', $brokerage, metadata: ['used_b' => $useB], severity: 'notice');
    }

    /** Which version a new chat sees: null without a test; else a or b, the same for one visitor. */
    public static function variant(?Site $brokerage, string $key): ?string
    {
        return self::for($brokerage)['on'] ? (crc32('ailly-ab|'.$key) % 2 === 0 ? 'a' : 'b') : null;
    }

    /**
     * The greeting and gate a chat sees.
     *
     * @param  array{name: string, greeting: string, gate: string}  $settings
     * @return array{greeting: string, gate: string}
     */
    public static function apply(?Site $brokerage, ?string $variant, array $settings, string $name): array
    {
        $t = self::for($brokerage);
        if ($variant !== 'b' || ! $t['on']) {
            return ['greeting' => AillySettings::greeting($settings, $name), 'gate' => $settings['gate']];
        }

        return ['greeting' => $t['greeting_b'] !== '' ? $t['greeting_b'] : AillySettings::greeting($settings, $name), 'gate' => $t['gate_b'] ?? $settings['gate']];
    }

    /**
     * Results since the test started: chats, leads and bookings for each version, and a verdict.
     *
     * @return array{a: array{chats: int, leads: int, booked: int, rate: float}, b: array{chats: int, leads: int, booked: int, rate: float}, verdict: string, winner: string|null}|null
     */
    public function results(?Site $brokerage): ?array
    {
        $t = self::for($brokerage);
        if ($t['started_at'] === null) {
            return null;
        }
        $out = [];
        foreach (['a', 'b'] as $v) {
            $q = fn () => DB::connection(Connections::cell())->table('ailly_conversations')->where('tenant_id', $this->tenants->id())->where('variant', $v)
                ->where('created_at', '>=', CarbonImmutable::parse($t['started_at']));
            $chats = $q()->count();
            $leads = $q()->where('outcome', '!=', 'none')->count();
            $out[$v] = ['chats' => $chats, 'leads' => $leads, 'booked' => $q()->whereIn('outcome', ['call', 'showing', 'listing'])->count(),
                'rate' => $chats > 0 ? round($leads * 100 / $chats, 1) : 0.0];
        }
        [$verdict, $winner] = self::verdict($out['a']['chats'], $out['a']['leads'], $out['b']['chats'], $out['b']['leads']);

        return ['a' => $out['a'], 'b' => $out['b'], 'verdict' => $verdict, 'winner' => $winner];
    }

    /**
     * Two-proportion z-test on lead rates (95%, two-sided), in words.
     *
     * @return array{0: string, 1: string|null}
     */
    public static function verdict(int $na, int $la, int $nb, int $lb): array
    {
        if ($na < self::MIN_CHATS || $nb < self::MIN_CHATS) {
            return ['Keep it running: each version needs at least '.self::MIN_CHATS.' chats before the results mean much.', null];
        }
        $pa = $la / $na;
        $pb = $lb / $nb;
        $p = ($la + $lb) / ($na + $nb);
        $se = sqrt($p * (1 - $p) * (1 / $na + 1 / $nb));
        if ($se == 0.0) {
            return ['No difference so far.', null];
        }
        $z = ($pb - $pa) / $se;
        if (abs($z) < 1.96) {
            return ['No clear winner yet: the difference could be chance. Keep it running, or keep what you have.', null];
        }
        $better = $z > 0 ? 'b' : 'a';
        $points = round(abs($pb - $pa) * 100, 1);

        return ['Version '.strtoupper($better).' turns '.$points.' more chats in every 100 into leads, and that is very unlikely to be chance.', $better];
    }

    /** @param array<string, mixed> $test */
    private function store(Site $brokerage, array $test): void
    {
        $brokerage->settings = [...($brokerage->settings ?? []), 'ailly_test' => $test];
        $brokerage->save();
    }
}
