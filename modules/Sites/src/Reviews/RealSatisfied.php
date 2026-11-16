<?php

declare(strict_types=1);

namespace Modules\Sites\Reviews;

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Modules\Core\Connect\ConnectionData;
use Modules\Core\Connect\Connector;
use Modules\Core\Connect\ConnectorKit;
use SimpleXMLElement;
use Throwable;

/**
 * RealSatisfied (specs/connect/20, C8a): the agent's public review feed, by the vanity key on their RealSatisfied
 * profile (Agent Dashboard → Publishing). Read daily; only what the agent chose to publish is in it.
 */
final class RealSatisfied implements Connector
{
    public const FEED = 'https://rss.realsatisfied.com/rss/agent/';

    public function __construct(private readonly ConnectorKit $kit, private readonly Reviews $reviews) {}

    public function verify(array $credentials, array $settings): string
    {
        $key = self::key($credentials + $settings);
        if ($key === null) {
            throw ValidationException::withMessages(['vanity_key' => 'Enter the vanity key from your RealSatisfied profile link (for example Jane-Doe).']);
        }
        if (! $this->fetch($key)->successful()) {
            throw ValidationException::withMessages(['vanity_key' => 'RealSatisfied has no public feed for "'.$key.'". Check the key and that publishing is on.']);
        }

        return 'RealSatisfied: '.$key;
    }

    public function sync(ConnectionData $connection): array
    {
        $key = self::key($connection->credentials + $connection->settings) ?? throw ValidationException::withMessages(['vanity_key' => 'Enter your RealSatisfied vanity key.']);
        $r = $this->fetch($key);
        $r->throw();
        try {
            $xml = new SimpleXMLElement($r->body(), LIBXML_NONET | LIBXML_NOCDATA);
        } catch (Throwable) {
            return ['created' => 0, 'updated' => 0, 'skipped' => 1, 'cursor' => null, 'note' => 'RealSatisfied\'s feed could not be read.'];
        }
        $rows = [];
        foreach ($xml->channel->item ?? [] as $item) {
            $extra = [];
            foreach ($item->getNamespaces(true) as $prefix => $ns) {
                foreach ($item->children($ns) as $name => $v) {
                    $extra[strtolower((string) $name)] = trim((string) $v);
                }
            }
            $guid = trim((string) ($item->guid ?? '')) ?: sha1((string) $item->title.(string) $item->pubDate.(string) $item->description);
            $rating = null;
            foreach (['satisfaction', 'overall', 'rating'] as $k) {
                if (isset($extra[$k]) && is_numeric($extra[$k])) {
                    $v = (float) $extra[$k];
                    $rating = (int) round($v > 5 ? $v / 20 : $v); // RealSatisfied scores out of 100
                    break;
                }
            }
            $date = trim((string) ($item->pubDate ?? ''));
            $rows[] = ['external_id' => $guid, 'author' => ($extra['customer_name'] ?? $extra['customername'] ?? null) ?: (trim((string) $item->title) ?: null),
                'rating' => $rating, 'text' => trim(html_entity_decode(strip_tags((string) $item->description))) ?: null,
                'url' => str_starts_with(trim((string) $item->link), 'https://') ? trim((string) $item->link) : null,
                'reviewed_at' => $date !== '' ? CarbonImmutable::parse($date) : CarbonImmutable::now()];
        }
        $n = $this->reviews->save('realsatisfied', $connection->ownerUserId, $connection->id, array_slice($rows, 0, 200));
        $this->kit->saveSettings($connection, ['interval_minutes' => 1440]);

        return ['created' => 0, 'updated' => $n, 'skipped' => 0, 'cursor' => null, 'note' => $n === 0 ? 'No published reviews yet.' : null];
    }

    private function fetch(string $key): Response
    {
        return Http::timeout(20)->accept('application/rss+xml, application/xml')->get(self::FEED.rawurlencode($key));
    }

    /** @param  array<string, mixed>  $in */
    private static function key(array $in): ?string
    {
        $key = trim((string) ($in['vanity_key'] ?? ''));
        $key = (string) preg_replace('#^https?://(www\.)?realsatisfied\.com/#i', '', $key);

        return preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{1,80}$/', $key) === 1 ? $key : null;
    }
}
