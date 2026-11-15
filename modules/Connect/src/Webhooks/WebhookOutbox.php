<?php

declare(strict_types=1);

namespace Modules\Connect\Webhooks;

use Modules\Connect\Jobs\DeliverWebhook;
use Modules\Connect\Models\Connection;
use Modules\Connect\Models\WebhookDelivery;
use Modules\Core\Connect\Outbox;

/** Queues one delivery per webhook endpoint that asked for the event (specs/connect/01). */
final class WebhookOutbox implements Outbox
{
    public function emit(string $event, array $data): void
    {
        if (! isset(self::EVENTS[$event])) {
            return;
        }
        try {
            $endpoints = Connection::query()->where('app_key', 'webhooks')->whereIn('status', ['connected', 'error'])->get()
                ->filter(fn (Connection $c): bool => in_array($event, (array) ($c->settings['events'] ?? []), true));
            foreach ($endpoints as $endpoint) {
                $this->queue($endpoint, $event, $data);
            }
        } catch (\Throwable $e) {
            report($e); // never break the action that caused the event
        }
    }

    /** @param array<string, mixed> $data */
    public function queue(Connection $endpoint, string $event, array $data): WebhookDelivery
    {
        $delivery = WebhookDelivery::query()->create(['connection_id' => $endpoint->id, 'event' => $event, 'payload' => $data, 'status' => 'pending']);
        DeliverWebhook::dispatch($delivery->id);

        return $delivery;
    }
}
