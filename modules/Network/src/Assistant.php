<?php

declare(strict_types=1);

namespace Modules\Network;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use Modules\AI\AiGateway;
use Modules\AI\Data\AiTask;
use Modules\AI\Exceptions\InsufficientCredits;
use Modules\Core\Tenancy\TenantContext;
use Modules\Crm\FairHousing;
use Modules\Listings\Models\Listing;
use Modules\Recruiting\Models\MlsAgent;
use Throwable;

/**
 * The AI networking assistant (AR3, specs/network/03). The agent says, in their own words, what a client who is moving
 * needs; the assistant reads it and finds:
 *
 * 1. agents in the RightAlly referral network who cover that area, by Trust Score, with a call button when shared;
 * 2. the top local agents on that side (listing or buying) from the brokerage's own MLS data, by closings there in
 *    the last 12 months, with an AI-drafted first message the agent copies and sends themselves.
 *
 * RightAlly never contacts anyone, never sends in bulk, and the client's details never go to the AI.
 */
final class Assistant
{
    public const MATCH = 'network.match';

    public const INTRO = 'network.intro';

    public function __construct(private readonly TenantContext $tenants, private readonly Profiles $profiles) {}

    /**
     * @return array{side: string|null, area: string|null, price_band: string|null, timing: string|null, language: string|null, needs: string|null, unclear: list<string>}
     */
    public function understand(string $words, User $by): array
    {
        $words = trim($words);
        if (mb_strlen($words) < 10) {
            throw ValidationException::withMessages(['words' => 'Tell me a little more: where, buying or selling, and anything that matters.']);
        }
        try {
            $j = (array) app(AiGateway::class)->run(new AiTask(self::MATCH, 'network.match', 'network-match:'.$by->id.':'.now()->format('YmdHisv'),
                untrustedContent: [mb_substr($words, 0, 2000)],
                outputSchema: ['type' => 'object', 'required' => ['unclear'], 'properties' => ['unclear' => ['type' => 'array']]], userId: $by->id))->json;
        } catch (InsufficientCredits $e) {
            throw ValidationException::withMessages(['words' => $e->getMessage()]);
        } catch (Throwable) {
            throw ValidationException::withMessages(['words' => 'I couldn\'t read that just now. Please use the search boxes instead.']);
        }
        $text = fn (string $k, int $max): ?string => is_string($j[$k] ?? null) && trim((string) $j[$k]) !== '' ? mb_substr(trim((string) $j[$k]), 0, $max) : null;

        return ['side' => in_array($j['side'] ?? null, ['buyer', 'seller'], true) ? (string) $j['side'] : null, 'area' => $text('area', 80), 'price_band' => $text('price_band', 40),
            'timing' => $text('timing', 60), 'language' => $text('language', 30), 'needs' => $text('needs', 300),
            'unclear' => array_values(array_map(fn ($u): string => mb_substr((string) $u, 0, 200), array_filter((array) ($j['unclear'] ?? []), 'is_string')))];
    }

    /**
     * The top local agents for one side in a city, from the brokerage's own MLS data (last 12 months).
     *
     * @return list<array{key: string, name: string, office: string|null, sides: int, volume_cents: int, ours: bool}>
     */
    public function localAgents(string $area, ?string $side, int $limit = 10): array
    {
        $city = trim(explode(',', $area)[0]);
        if (mb_strlen($city) < 2) {
            return [];
        }
        $since = CarbonImmutable::today()->subYear()->toDateString();
        $q = Listing::query()->toBase()->where('standard_status', 'Closed')->where('close_date', '>=', $since)
            ->where(fn ($w) => preg_match('/^\d{5}$/', $city) === 1 ? $w->where('postal_code', $city) : $w->where('city', $city));
        $counts = [];
        foreach ($q->get(['list_agent_mls_id', 'list_agent_name', 'list_office_name', 'buyer_agent_mls_id', 'close_price_cents', 'mls_source_id']) as $l) {
            $ids = match ($side) {
                'seller' => [[$l->list_agent_mls_id, $l->list_agent_name, $l->list_office_name]],
                'buyer' => [[$l->buyer_agent_mls_id, null, null]],
                default => [[$l->list_agent_mls_id, $l->list_agent_name, $l->list_office_name], [$l->buyer_agent_mls_id, null, null]],
            };
            foreach ($ids as [$id, $name, $office]) {
                if ($id === null || $id === '') {
                    continue;
                }
                $key = $l->mls_source_id.':'.$id;
                $counts[$key] ??= ['source' => (int) $l->mls_source_id, 'id' => (string) $id, 'name' => $name, 'office' => $office, 'sides' => 0, 'volume_cents' => 0];
                $counts[$key]['sides']++;
                $counts[$key]['volume_cents'] += (int) $l->close_price_cents;
                $counts[$key]['name'] ??= $name;
                $counts[$key]['office'] ??= $office;
            }
        }
        uasort($counts, fn (array $a, array $b): int => [$b['sides'], $b['volume_cents']] <=> [$a['sides'], $a['volume_cents']]);
        $top = array_slice($counts, 0, $limit, true);
        $known = MlsAgent::query()->whereIn('agent_mls_id', array_column($top, 'id'))->get()->keyBy(fn (MlsAgent $a): string => $a->mls_source_id.':'.$a->agent_mls_id);
        $out = [];
        foreach ($top as $key => $a) {
            $m = $known->get($key);
            $name = (string) ($m->name ?? $a['name'] ?? '');
            if ($name === '') {
                continue;
            }
            $out[] = ['key' => (string) $key, 'name' => $name, 'office' => $m->office_name ?? $a['office'], 'sides' => $a['sides'], 'volume_cents' => $a['volume_cents'], 'ours' => (bool) ($m->ours ?? false)];
        }

        return $out;
    }

    /**
     * A first message to a local agent, for the agent to send themselves. Never includes the client's details.
     *
     * @param  array<string, mixed>  $criteria
     */
    public function intro(User $by, string $agentName, ?string $office, array $criteria): string
    {
        $client = implode(', ', array_filter([
            ($criteria['side'] ?? null) === 'seller' ? 'selling a home' : (($criteria['side'] ?? null) === 'buyer' ? 'buying a home' : 'moving'),
            isset($criteria['area']) ? 'in '.$criteria['area'] : null, $criteria['price_band'] ?? null, $criteria['timing'] ?? null,
            isset($criteria['language']) ? 'prefers '.$criteria['language'] : null,
        ]));
        try {
            $result = app(AiGateway::class)->run(new AiTask(self::INTRO, 'network.intro', 'network-intro:'.$by->id.':'.now()->format('YmdHisv'),
                inputs: ['writer' => $by->name, 'brokerage' => $this->tenants->current()->name, 'client' => mb_substr($client, 0, 200)],
                untrustedContent: ['The other agent: '.mb_substr($agentName, 0, 120).($office !== null && $office !== '' ? ', '.mb_substr($office, 0, 120) : '')],
                outputSchema: ['type' => 'object', 'required' => ['message'], 'properties' => ['message' => ['type' => 'string']]], userId: $by->id));
        } catch (InsufficientCredits $e) {
            throw ValidationException::withMessages(['intro' => $e->getMessage()]);
        } catch (Throwable) {
            throw ValidationException::withMessages(['intro' => 'I couldn\'t write that just now. Please try again in a minute.']);
        }
        $message = trim((string) ($result->json['message'] ?? ''));
        if ($message === '' || FairHousing::screen($message, 'ai_content', 'removed', 'Networking introduction') !== []) {
            throw ValidationException::withMessages(['intro' => 'I couldn\'t write a suitable message. Please write your own.']);
        }

        return $message;
    }

    /**
     * Everything the assistant found for the criteria.
     *
     * @param  array{side: string|null, area: string|null, language: string|null}  $c
     * @return array{network: list<array<string, mixed>>, local: list<array{key: string, name: string, office: string|null, sides: int, volume_cents: int, ours: bool}>}
     */
    public function find(array $c, User $by): array
    {
        if ($c['area'] === null) {
            return ['network' => [], 'local' => []];
        }
        $mine = $this->profiles->mine($by);
        $network = array_values(array_filter($this->profiles->search($c['area'], $c['side'], $c['language'], 10), fn ($p): bool => $mine === null || (int) $p->id !== (int) $mine->id));
        $hasMls = Listing::query()->toBase()->limit(1)->exists();

        return ['network' => array_map(fn ($p): array => Profiles::card($p), $network), 'local' => $hasMls ? $this->localAgents($c['area'], $c['side']) : []];
    }
}
