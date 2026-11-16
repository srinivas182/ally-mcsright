<?php

declare(strict_types=1);

namespace Modules\Movewise;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\AI\AiGateway;
use Modules\AI\Data\AiTask;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\TenantContext;
use Modules\Crm\FairHousing;
use Modules\Listings\Search\ListingSearch;
use Modules\Movewise\Growth\HomeValues;
use Modules\Movewise\Models\Person;
use Throwable;

/**
 * AIlly for Movewise (specs/movewise/02): answers about a home, affordability, value, selling and moving, in English
 * or Spanish. It is HQ's assistant (run as the HQ account; HQ pays), never pretends to be a person, never gives legal,
 * tax or lending advice, follows Fair Housing rules, and never asks for a phone number. Conversations are kept so it
 * remembers, and deleted with the account.
 */
final class MovewiseAilly
{
    public const FEATURE = 'movewise.ailly';

    private const KEEP = 40;

    public function __construct(private readonly Movewise $movewise, private readonly ListingSearch $search) {}

    /**
     * @return array{chat: string, reply: string}
     */
    public function reply(?string $chatUlid, string $text, ?string $listing, ?Person $person, string $locale): array
    {
        $text = trim(mb_substr(strip_tags($text), 0, 1000));
        if ($text === '') {
            throw ValidationException::withMessages(['message' => 'Ask a question.']);
        }
        $db = DB::connection(Connections::control())->table('movewise_chats');
        $chat = $chatUlid !== null && preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/', $chatUlid) === 1 ? $db->where('ulid', $chatUlid)->first() : null;
        if ($chat !== null && $chat->person_id !== null && $chat->person_id !== $person?->id) {
            $chat = null; // someone else's conversation: start a new one
        }
        $history = $chat !== null ? (array) json_decode((string) $chat->messages, true) : [];
        $hq = $this->movewise->feedTenant();
        $reply = '';
        if ($hq !== null) {
            $facts = $listing !== null ? $this->home($hq->id, $listing) : null;
            // Asked what the home is worth: add ATTOM's estimate (HQ-paid, cached 30 days, capped monthly).
            if ($facts !== null && preg_match('/\b(worth|value|valued|overpriced|underpriced|estimate|appraise|vale|valor|precio justo)\b/iu', $text) === 1) {
                $value = $this->value($hq->id, $listing, $person);
                if ($value !== null) {
                    $facts['value_estimate'] = $value;
                }
            }
            try {
                $r = app(TenantContext::class)->runAs($hq, fn () => app(AiGateway::class)->run(new AiTask(self::FEATURE, 'movewise.ailly', 'movewise-ailly:'.Str::ulid(),
                    inputs: ['language' => $locale], untrustedContent: array_values(array_filter([
                        $facts !== null ? 'The home being viewed: '.json_encode($facts) : null,
                        $history !== [] ? 'The conversation so far: '.json_encode(array_slice($history, -10)) : null,
                        'The person asks: '.$text,
                    ])), outputSchema: ['type' => 'object', 'properties' => ['reply' => ['type' => 'string']]])));
                $reply = trim(mb_substr((string) ($r->json['reply'] ?? ''), 0, 2000));
            } catch (Throwable $e) {
                report($e);
            }
        }
        // Screened as HQ (the Fair Housing log is HQ's); a reply that slips is replaced, never shown.
        if ($reply === '' || app(TenantContext::class)->runAs($hq, fn (): array => FairHousing::screen($reply, 'ads', 'removed', 'Movewise AIlly')) !== []) {
            $reply = $locale === 'es'
                ? 'Puedo ayudarte con las casas, lo que puedes pagar, el valor de una casa y tu mudanza. ¿Qué te gustaría saber de la casa o de tu plan?'
                : 'I can help with homes, what you can afford, a home\'s value and your move. What would you like to know about the home or your plans?';
        }
        $now = CarbonImmutable::now()->toIso8601String();
        $history = array_slice([...$history, ['role' => 'person', 'text' => $text, 'at' => $now], ['role' => 'ailly', 'text' => $reply, 'at' => $now]], -self::KEEP);
        if ($chat === null) {
            $ulid = (string) Str::ulid();
            $db->insert(['ulid' => $ulid, 'person_id' => $person?->id, 'locale' => $locale, 'messages' => json_encode($history), 'created_at' => $now, 'updated_at' => $now]);
        } else {
            $ulid = (string) $chat->ulid;
            $db->where('id', $chat->id)->update(['messages' => json_encode($history), 'person_id' => $chat->person_id ?? $person?->id, 'updated_at' => $now]);
        }

        return ['chat' => $ulid, 'reply' => $reply];
    }

    /** @return array<string, mixed>|null ATTOM's range for the home being viewed, or null when there isn't one */
    private function value(int $tenantId, string $ulid, ?Person $person): ?array
    {
        $h = $this->search->find($tenantId, $ulid);
        if ($h === null || ! isset($h['address'], $h['postal_code'])) {
            return null;
        }
        try {
            $v = app(HomeValues::class)->for(['street' => (string) $h['address'], 'city' => (string) ($h['city'] ?? ''), 'state' => (string) ($h['state'] ?? ''),
                'zip' => substr((string) $h['postal_code'], 0, 5)], $person);
        } catch (ValidationException) {
            return null;
        }

        return $v === null ? null : ['low' => $v['low'], 'mid' => $v['mid'], 'high' => $v['high'], 'source' => 'ATTOM automated valuation, '.$v['as_of'], 'last_sale' => $v['last_sale']];
    }

    /** @return array<string, mixed>|null */
    private function home(int $tenantId, string $ulid): ?array
    {
        $h = $this->search->find($tenantId, $ulid);
        if ($h === null || ! in_array((string) ($h['state'] ?? ''), $this->movewise->onStates(), true)) {
            return null;
        }
        $price = (int) ($h['price'] ?? 0);

        return array_filter(['address' => ListingSearch::label($h), 'price' => $price ?: null, 'beds' => $h['beds'] ?? null, 'baths' => $h['baths_full'] ?? null,
            'sqft' => $h['sqft'] ?? null, 'year_built' => $h['year_built'] ?? null, 'status' => $h['status'] ?? null, 'remarks' => isset($h['remarks']) ? mb_substr((string) $h['remarks'], 0, 1200) : null,
            'monthly_cost' => $price > 0 ? MonthlyCost::for($price, (string) $h['state']) : null], fn ($v): bool => $v !== null);
    }
}
