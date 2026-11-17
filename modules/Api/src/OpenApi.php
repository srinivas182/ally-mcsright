<?php

declare(strict_types=1);

namespace Modules\Api;

/** The OpenAPI 3.1 description of API v1 (API1), served at /api/v1/openapi.json for partners and tools. */
final class OpenApi
{
    /** @return array<string, mixed> */
    public static function document(string $server): array
    {
        $ref = fn (string $n): array => ['$ref' => '#/components/schemas/'.$n];
        $list = fn (string $n): array => ['type' => 'object', 'properties' => ['data' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/'.$n]],
            'next_cursor' => ['type' => ['string', 'null']]]];
        $one = fn (string $n): array => ['type' => 'object', 'properties' => ['data' => ['$ref' => '#/components/schemas/'.$n]]];
        $ok = fn (array $schema, string $d = 'OK'): array => ['description' => $d, 'content' => ['application/json' => ['schema' => $schema]]];
        $paging = [['name' => 'cursor', 'in' => 'query', 'schema' => ['type' => 'string']], ['name' => 'limit', 'in' => 'query', 'schema' => ['type' => 'integer', 'maximum' => 200]]];
        $id = [['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string']]];
        $idem = ['name' => 'Idempotency-Key', 'in' => 'header', 'schema' => ['type' => 'string'], 'description' => 'Retries with the same key return the first answer (24 h).'];
        $body = fn (array $props, array $required = []): array => ['required' => true, 'content' => ['application/json' => ['schema' => ['type' => 'object',
            'properties' => $props, 'required' => $required]]]];
        $s = fn (string $type = 'string'): array => ['type' => $type];

        return [
            'openapi' => '3.1.0',
            'info' => ['title' => 'RightAlly API', 'version' => '2026-11-01',
                'description' => 'Contacts, tasks, deals and webhooks for one brokerage. Every call acts as a person and sees only what they see. No deletes, bulk messaging, money movements, bank details or commission splits.'],
            'servers' => [['url' => $server]],
            'security' => [['apiKey' => []], ['oauth' => []]],
            'paths' => [
                '/me' => ['get' => ['summary' => 'Who the key or token acts as', 'responses' => ['200' => ['description' => 'OK']]]],
                '/contacts' => [
                    'get' => ['summary' => 'Contacts you can see', 'parameters' => array_merge($paging, [['name' => 'updated_since', 'in' => 'query', 'schema' => ['type' => 'string', 'format' => 'date-time']],
                        ['name' => 'q', 'in' => 'query', 'schema' => $s()]]), 'responses' => ['200' => $ok($list('Contact'))], 'x-scope' => 'contacts:read'],
                    'post' => ['summary' => 'Add a contact', 'parameters' => [$idem], 'requestBody' => $body(['first_name' => $s(), 'last_name' => $s(), 'email' => $s(), 'phone' => $s(),
                        'city' => $s(), 'state' => $s(), 'zip' => $s()], ['first_name']), 'responses' => ['201' => $ok($one('Contact'), 'Created')], 'x-scope' => 'contacts:write'],
                ],
                '/contacts/{id}' => [
                    'get' => ['summary' => 'One contact', 'parameters' => $id, 'responses' => ['200' => $ok($one('Contact'))], 'x-scope' => 'contacts:read'],
                    'patch' => ['summary' => 'Change a contact', 'parameters' => $id, 'requestBody' => $body(['first_name' => $s(), 'last_name' => $s(), 'city' => $s(), 'state' => $s(),
                        'zip' => $s(), 'lifecycle' => ['type' => 'string', 'enum' => ['lead', 'active', 'under_contract', 'past_client', 'sphere']]]),
                        'responses' => ['200' => $ok($one('Contact'))], 'x-scope' => 'contacts:write'],
                ],
                '/contacts/{id}/notes' => ['post' => ['summary' => 'Add a note', 'parameters' => array_merge($id, [$idem]), 'requestBody' => $body(['body' => $s()], ['body']),
                    'responses' => ['201' => ['description' => 'Created']], 'x-scope' => 'contacts:write']],
                '/contacts/{id}/tasks' => ['post' => ['summary' => 'Add a task', 'parameters' => array_merge($id, [$idem]), 'requestBody' => $body(['title' => $s(),
                    'due_at' => ['type' => 'string', 'format' => 'date-time'], 'type' => $s(), 'notes' => $s()], ['title', 'due_at']), 'responses' => ['201' => $ok($one('Task'), 'Created')],
                    'x-scope' => 'contacts:write']],
                '/tasks' => ['get' => ['summary' => 'Your tasks', 'parameters' => array_merge($paging, [['name' => 'status', 'in' => 'query', 'schema' => ['type' => 'string',
                    'enum' => ['open', 'done', 'all']]]]), 'responses' => ['200' => $ok($list('Task'))], 'x-scope' => 'tasks:read']],
                '/deals' => ['get' => ['summary' => 'Deals you work on', 'parameters' => array_merge($paging, [['name' => 'status', 'in' => 'query', 'schema' => $s()]]),
                    'responses' => ['200' => $ok($list('Deal'))], 'x-scope' => 'deals:read']],
                '/deals/{id}' => ['get' => ['summary' => 'One deal', 'parameters' => $id, 'responses' => ['200' => $ok($one('Deal'))], 'x-scope' => 'deals:read']],
                '/webhooks' => [
                    'get' => ['summary' => 'Webhooks this key or app made', 'responses' => ['200' => ['description' => 'OK']], 'x-scope' => 'webhooks:manage'],
                    'post' => ['summary' => 'Send events to a URL (signed; the secret is shown once)', 'requestBody' => $body(['url' => $s(), 'events' => ['type' => 'array',
                        'items' => ['type' => 'string', 'enum' => ['lead.created', 'contact.created', 'contact.stage_changed', 'booking.created', 'task.completed']]]], ['url', 'events']),
                        'responses' => ['201' => ['description' => 'Created']], 'x-scope' => 'webhooks:manage'],
                ],
                '/webhooks/{id}' => ['delete' => ['summary' => 'Stop a webhook', 'parameters' => $id, 'responses' => ['204' => ['description' => 'Stopped']], 'x-scope' => 'webhooks:manage']],
            ],
            'components' => [
                'securitySchemes' => [
                    'apiKey' => ['type' => 'http', 'scheme' => 'bearer', 'description' => 'A personal API key ("ra_key_…") from Settings › API and partner apps.'],
                    'oauth' => ['type' => 'oauth2', 'flows' => ['authorizationCode' => ['authorizationUrl' => '/oauth/authorize', 'tokenUrl' => '/oauth/token',
                        'scopes' => array_combine(array_map(fn (string $k): string => Scopes::oauth($k), array_keys(Scopes::ALL)), array_values(Scopes::ALL))]]],
                ],
                'schemas' => [
                    'Contact' => ['type' => 'object', 'properties' => ['id' => $s(), 'first_name' => $s(), 'last_name' => $s(), 'email' => ['type' => ['string', 'null']],
                        'phone' => ['type' => ['string', 'null']], 'city' => ['type' => ['string', 'null']], 'state' => ['type' => ['string', 'null']], 'zip' => ['type' => ['string', 'null']],
                        'lifecycle' => $s(), 'source' => $s(), 'owner' => ['type' => ['string', 'null']], 'consent' => ['type' => 'object'], 'created_at' => $s(), 'updated_at' => $s()]],
                    'Task' => ['type' => 'object', 'properties' => ['id' => $s('integer'), 'title' => $s(), 'type' => $s(), 'priority' => $s(), 'due_at' => $s(),
                        'completed_at' => ['type' => ['string', 'null']], 'contact' => ['type' => ['string', 'null']]]],
                    'Deal' => ['type' => 'object', 'properties' => ['id' => $s(), 'type' => $s(), 'status' => $s(), 'street' => $s(), 'city' => $s(), 'state' => $s(), 'zip' => $s(),
                        'mls_number' => ['type' => ['string', 'null']], 'price' => $s('number'), 'contract_date' => ['type' => ['string', 'null']],
                        'closing_date' => ['type' => ['string', 'null']], 'closed_on' => ['type' => ['string', 'null']], 'agent' => $s(), 'contact' => ['type' => ['string', 'null']],
                        'updated_at' => $s()]],
                    'Error' => ['type' => 'object', 'properties' => ['error' => ['type' => 'object', 'properties' => ['code' => $s(), 'message' => $s(), 'fields' => ['type' => 'object']]]]],
                ],
            ],
        ];
    }
}
