<?php

declare(strict_types=1);

namespace Modules\Crm\Connect;

use Carbon\CarbonImmutable;
use Modules\Core\Connect\ConnectionData;
use Modules\Core\Connect\ConnectorKit;
use Modules\Crm\Consent;
use Modules\Crm\Models\ConsentEvent;
use Modules\Crm\Models\Contact;

/**
 * Email marketing sync (specs/connect/15, C7a), shared by Mailchimp and Constant Contact.
 *
 * - Only people who may get marketing email go: by default those who agreed to email here; if the broker widened it
 *   (with permission confirmed), everyone with an email address who hasn't opted out. Opted-out people never go,
 *   and anyone we sent who later opts out here is unsubscribed in the app.
 * - Unsubscribes (and cleaned addresses) in the app come back as email opt-outs, with the app as their source.
 * - A brokerage's app gets the brokerage's contacts, never agents' personal ones; an agent's app gets their own.
 * - Upserts by email, so repeating a run never duplicates anyone. 200 people a run; "more" until caught up.
 */
final class EmailMarketing
{
    public const BATCH = 200;

    public function __construct(private readonly ConnectorKit $kit, private readonly Consent $consent) {}

    /** @return array{created: int, updated: int, skipped: int, cursor: string|null, note: string|null, more?: bool} */
    public function run(ConnectionData $connection, EmailApp $app): array
    {
        $audience = (string) ($connection->settings['audience'] ?? '');
        if ($audience === '') {
            return ['created' => 0, 'updated' => 0, 'skipped' => 0, 'cursor' => $connection->cursor, 'note' => 'Choose an audience under Settings → Email marketing.'];
        }
        $cursor = (array) json_decode((string) ($connection->cursor ?? '{}'), true);
        if (($cursor['audience'] ?? null) !== $audience) {
            $cursor = []; // a new audience: everyone again
        }
        $now = CarbonImmutable::now();

        // 1. Unsubscribes in the app stop email here.
        $stopped = 0;
        foreach ($app->unsubscribedSince($connection, $audience, is_string($cursor['unsubs'] ?? null) ? $cursor['unsubs'] : null) as $email) {
            $stopped += $this->optOut($email, $app->name()) ? 1 : 0;
        }

        // 2. People who changed since the last run: add or update the ones who may get email; unsubscribe opted-out ones we sent.
        $since = is_string($cursor['pushed'] ?? null) ? CarbonImmutable::parse($cursor['pushed']) : null;
        $entity = 'email:'.substr(hash('md5', $audience), 0, 20); // fits the 30-character entity
        $wide = ($connection->settings['who'] ?? 'consented') === 'not_opted_out';
        // A brokerage's app gets the brokerage's contacts (never agents' personal ones); an agent's app gets their own.
        $rows = Contact::query()->whereNotNull('primary_email')->when($since !== null, fn ($q) => $q->where('updated_at', '>', $since))
            ->when($connection->ownerUserId === null, fn ($q) => $q->where('origin', '!=', 'personal'), fn ($q) => $q->where('owner_user_id', $connection->ownerUserId))
            ->orderBy('updated_at')->orderBy('id')->limit(self::BATCH + 1)->with('tags')->get();
        $more = $rows->count() > self::BATCH;
        $rows = $rows->take(self::BATCH);
        $added = 0;
        $updated = 0;
        $skipped = 0;
        $last = $since;
        foreach ($rows as $c) {
            $last = CarbonImmutable::parse((string) $c->getAttribute('updated_at'));
            $email = mb_strtolower((string) $c->primary_email);
            $key = hash('md5', $email);
            $sent = $this->kit->localId($connection, $entity, $key) !== null;
            $optedOut = ConsentEvent::query()->where('contact_id', $c->id)->where('channel', 'email')->latest('id')->value('action') === 'opt_out';
            if ($optedOut || ! ($wide || $c->email_opt_in)) {
                if ($optedOut && $sent) {
                    $app->unsubscribe($connection, $audience, $email);
                    $updated++;
                } else {
                    $skipped++;
                }

                continue;
            }
            $app->upsert($connection, $audience, ['email' => $email, 'first_name' => $c->first_name, 'last_name' => $c->last_name,
                'tags' => array_slice(array_values(array_filter($c->tags->pluck('name')->map(fn ($t): string => (string) $t)->all())), 0, 20)]);
            if ($sent) {
                $updated++;
            } else {
                $this->kit->remember($connection, $entity, $key, $c->id);
                $added++;
            }
        }
        $this->kit->saveSettings($connection, ['in_audience' => (int) ($connection->settings['in_audience'] ?? 0) + $added, 'last_push_at' => $now->toIso8601String(),
            'unsubs_month' => ($connection->settings['unsubs_month_key'] ?? null) === $now->format('Y-m') ? (int) ($connection->settings['unsubs_month'] ?? 0) + $stopped : $stopped,
            'unsubs_month_key' => $now->format('Y-m')]);

        return ['created' => $added, 'updated' => $updated + $stopped, 'skipped' => $skipped,
            'cursor' => (string) json_encode(['audience' => $audience, 'pushed' => ($last ?? $now)->toIso8601String(), 'unsubs' => $now->toIso8601String()]),
            'note' => $stopped > 0 ? $stopped.' unsubscribed in '.$app->name().' and will get no more email here.' : null, 'more' => $more];
    }

    /** An unsubscribe from the app: no more marketing email here. True when it changed something. */
    public function optOut(string $email, string $appName): bool
    {
        $email = mb_strtolower(trim($email));
        $changed = false;
        foreach (Contact::query()->where('primary_email', $email)->get() as $c) {
            if (ConsentEvent::query()->where('contact_id', $c->id)->where('channel', 'email')->latest('id')->value('action') !== 'opt_out') {
                $this->consent->fromConnectedApp($c, $appName, false, true, false);
                $changed = true;
            }
        }

        return $changed;
    }
}
