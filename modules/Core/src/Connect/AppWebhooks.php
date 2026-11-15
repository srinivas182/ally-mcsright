<?php

declare(strict_types=1);

namespace Modules\Core\Connect;

use Symfony\Component\HttpFoundation\Response;

/**
 * A two-way app whose webhooks come to one address for the whole app (Meta, for example), not one per connection
 * (specs/connect/10). RightAlly's central address checks them and routes each part to the connection that owns the
 * other app's account (a page id, an account id), via the routes the driver records (ConnectorKit::route).
 */
interface AppWebhooks extends TwoWay
{
    /**
     * The other app's one-time check when HQ sets the address up (e.g. Meta's hub.challenge); null if not one.
     *
     * @param  array<string, mixed>  $query
     */
    public function handshake(array $query): ?Response;

    /**
     * Splits a checked webhook into the parts for each account it is about.
     *
     * @param  array<string, mixed>  $payload
     * @return list<array{key: string, payload: array<string, mixed>}>
     */
    public function route(array $payload): array;
}
