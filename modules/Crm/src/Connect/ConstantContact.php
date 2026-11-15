<?php

declare(strict_types=1);

namespace Modules\Crm\Connect;

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Modules\Core\Connect\ConnectionData;
use Modules\Core\Connect\Connector;
use Modules\Core\Connect\ConnectorKit;
use Modules\Core\Connect\NeedsReconnect;

/**
 * Constant Contact (specs/connect/15, C7a): V3 API with OAuth 2 (PKCE, rotating refresh tokens). People are added
 * or updated into the chosen list with the sign-up-form endpoint (create or update by email); unsubscribes are
 * read hourly (contacts with status "unsubscribed" updated since the last run).
 */
final class ConstantContact implements Connector, EmailApp
{
    public const API = 'https://api.cc.email/v3/';

    public function __construct(private readonly ConnectorKit $kit) {}

    public function name(): string
    {
        return 'Constant Contact';
    }

    public function verify(array $credentials, array $settings): string
    {
        return 'Constant Contact';
    }

    public function sync(ConnectionData $connection): array
    {
        return app(EmailMarketing::class)->run($connection, $this);
    }

    public function audiences(ConnectionData $connection): array
    {
        $r = self::check($this->http($connection)->get('contact_lists', ['limit' => 100, 'include_membership_count' => 'active']));
        $out = [];
        foreach ((array) $r->json('lists', []) as $l) {
            $out[] = ['id' => (string) ($l['list_id'] ?? ''), 'name' => (string) ($l['name'] ?? ''), 'members' => isset($l['membership_count']) ? (int) $l['membership_count'] : null];
        }

        return $out;
    }

    public function upsert(ConnectionData $connection, string $audience, array $person): void
    {
        self::check($this->http($connection)->post('contacts/sign_up_form', ['email_address' => $person['email'], 'first_name' => mb_substr($person['first_name'], 0, 50),
            'last_name' => mb_substr($person['last_name'], 0, 50), 'list_memberships' => [$audience]]));
    }

    /** Constant Contact has no "unsubscribe this person" for partners: we take them off the list instead. */
    public function unsubscribe(ConnectionData $connection, string $audience, string $email): void
    {
        $r = self::check($this->http($connection)->get('contacts', ['email' => $email, 'status' => 'all']));
        foreach ((array) $r->json('contacts', []) as $c) {
            if (is_string($c['contact_id'] ?? null)) {
                self::check($this->http($connection)->post('activities/remove_list_memberships', ['source' => ['contact_ids' => [$c['contact_id']]], 'list_ids' => [$audience]]));
            }
        }
    }

    public function unsubscribedSince(ConnectionData $connection, string $audience, ?string $since): array
    {
        $query = ['status' => 'unsubscribed', 'limit' => 500] + ($since !== null ? ['updated_after' => CarbonImmutable::parse($since)->utc()->format('Y-m-d\TH:i:s\Z')] : []);
        $r = self::check($this->http($connection)->get('contacts', $query));
        $out = [];
        foreach ((array) $r->json('contacts', []) as $c) {
            $email = $c['email_address']['address'] ?? null;
            if (is_string($email) && $email !== '') {
                $out[] = $email;
            }
        }

        return $out;
    }

    private function http(ConnectionData $connection): PendingRequest
    {
        return Http::withToken($this->kit->accessToken($connection))->acceptJson()->asJson()->timeout(20)->retry(2, 500, throw: false)->baseUrl(self::API);
    }

    private static function check(Response $r): Response
    {
        if ($r->status() === 401) {
            throw new NeedsReconnect('Constant Contact needs you to connect it again.');
        }
        if (! $r->successful()) {
            throw new \RuntimeException('Constant Contact refused the request: '.mb_substr((string) ($r->json('0.error_message') ?? $r->body()), 0, 200));
        }

        return $r;
    }
}
