<?php

declare(strict_types=1);

namespace Modules\Billing\Gateway;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Modules\Billing\PaymentSettings;
use Throwable;

/**
 * HQ3: checks HQ's Stripe setup step by step, in plain words: keys saved, Stripe accepts the secret key, the webhook
 * is added in Stripe with RightAlly's address and every event RightAlly needs, and Stripe has reached RightAlly with a
 * correctly signed event. It only reads from Stripe; nothing is created or charged.
 */
final class StripeSetupCheck
{
    /** The events RightAlly acts on, what each one is for (shown in the setup guide). */
    public const EVENTS = [
        'checkout.session.completed' => 'A sign-up or purchase was paid (creates the brokerage)',
        'invoice.paid' => 'A monthly or yearly renewal went through',
        'invoice.payment_failed' => 'A renewal failed (starts reminders and the grace period)',
        'customer.subscription.deleted' => 'A customer cancelled',
        'payment_intent.succeeded' => 'A one-time payment, such as a credit pack, went through',
        'payment_intent.payment_failed' => 'A one-time payment failed',
        'charge.refunded' => 'A payment was refunded',
        'account.updated' => 'A partner\'s payout account changed',
    ];

    private const LAST_EVENT = 'billing.webhook.last';

    public function __construct(private readonly PaymentSettings $settings) {}

    /** Called by the webhook once an event's signature is verified, so HQ can see that Stripe reaches RightAlly. */
    public static function received(string $type): void
    {
        Cache::forever(self::LAST_EVENT, ['type' => $type, 'at' => CarbonImmutable::now()->toIso8601String()]);
    }

    /**
     * @return list<array{step: string, ok: bool, message: string}>
     */
    public function run(string $webhookUrl): array
    {
        $mode = $this->settings->summary()['mode'];
        $keys = $this->settings->keys();
        if ($keys === null) {
            return [['step' => 'keys', 'ok' => false, 'message' => "No {$mode} keys are saved yet. Paste them in the {$mode} box below (step 2)."]];
        }
        $out = [['step' => 'keys', 'ok' => true, 'message' => ucfirst($mode).' keys are saved.']];

        try {
            $balance = Http::timeout(10)->withToken($keys['secret'])->acceptJson()->get('https://api.stripe.com/v1/balance');
        } catch (Throwable) {
            return [...$out, ['step' => 'secret', 'ok' => false, 'message' => 'Could not reach Stripe. Try again in a minute.']];
        }
        if (! $balance->successful()) {
            return [...$out, ['step' => 'secret', 'ok' => false, 'message' => 'Stripe did not accept the secret key. Copy it again from Stripe › Developers › API keys and save.']];
        }
        $out[] = ['step' => 'secret', 'ok' => true, 'message' => 'Stripe accepts the secret key.'];

        $endpoints = Http::timeout(10)->withToken($keys['secret'])->acceptJson()->get('https://api.stripe.com/v1/webhook_endpoints', ['limit' => 100]);
        $mine = collect((array) $endpoints->json('data'))->first(fn (mixed $e): bool => is_array($e) && rtrim((string) ($e['url'] ?? ''), '/') === rtrim($webhookUrl, '/'));
        if (! is_array($mine)) {
            $out[] = ['step' => 'webhook', 'ok' => false, 'message' => "No webhook in Stripe ({$mode} mode) points to {$webhookUrl}. Add it (step 3)."];
        } else {
            $out[] = ($mine['status'] ?? 'enabled') === 'enabled'
                ? ['step' => 'webhook', 'ok' => true, 'message' => 'The webhook is added in Stripe and switched on.']
                : ['step' => 'webhook', 'ok' => false, 'message' => 'The webhook is added in Stripe but switched off. Turn it on in Stripe.'];
            $events = (array) ($mine['enabled_events'] ?? []);
            $missing = in_array('*', $events, true) ? [] : array_values(array_diff(array_keys(self::EVENTS), $events));
            $out[] = $missing === []
                ? ['step' => 'events', 'ok' => true, 'message' => 'All '.count(self::EVENTS).' events RightAlly needs are selected.']
                : ['step' => 'events', 'ok' => false, 'message' => 'Add these events to the webhook in Stripe: '.implode(', ', $missing).'.'];
        }

        /** @var array{type: string, at: string}|null $last */
        $last = Cache::get(self::LAST_EVENT);
        $out[] = $last === null
            ? ['step' => 'received', 'ok' => false, 'message' => 'Stripe has not reached RightAlly yet. In Stripe, open the webhook and click "Send test event", then check again.']
            : ['step' => 'received', 'ok' => true, 'message' => 'Stripe reached RightAlly with a correctly signed event ('.$last['type'].', '.CarbonImmutable::parse($last['at'])->diffForHumans().').'];

        return $out;
    }
}
