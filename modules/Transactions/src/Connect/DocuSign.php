<?php

declare(strict_types=1);

namespace Modules\Transactions\Connect;

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Modules\Core\Connect\ConnectionData;
use Modules\Core\Connect\Connector;
use Modules\Core\Connect\ConnectorKit;
use Modules\Core\Connect\NeedsReconnect;
use Modules\Transactions\Models\Deal;

/**
 * DocuSign (specs/connect/12, Sprint C5b): every completed envelope is filed on the right deal automatically, as one
 * signed PDF (the envelope's combined documents, with the certificate), after a one-click sign-in.
 *
 * - Which deal: an envelope custom field named "RightAlly deal" holding the deal's id wins; otherwise the deal whose
 *   street address the envelope's subject names ("Please DocuSign: 44 Pecan Dr purchase contract"), matched like
 *   file exports, never guessed when two deals share the address. An agent's own connection files only on their
 *   own deals.
 * - Envelopes completed since the last check are read every 15 minutes (the first run goes back a year). Envelopes
 *   that name no deal yet are kept and tried again on each run (for 30 days), so a deal added later still gets them.
 * - Read only: nothing is sent or changed in DocuSign.
 */
final class DocuSign implements Connector
{
    public const USERINFO = 'https://account.docusign.com/oauth/userinfo';

    public const DEAL_FIELD = 'RightAlly deal';

    private const PER_RUN = 50;

    public function __construct(private readonly ConnectorKit $kit, private readonly DealImporter $importer) {}

    public function verify(array $credentials, array $settings): string
    {
        return 'DocuSign'; // connected with one click (OAuth), not a pasted key
    }

    public function sync(ConnectionData $connection): array
    {
        $token = $this->kit->accessToken($connection);
        [$account, $base] = $this->account($connection, $token);
        $http = Http::withToken($token)->acceptJson()->timeout(30)->baseUrl(rtrim($base, '/')."/restapi/v2.1/accounts/{$account}/");
        $since = is_string($connection->cursor) && $connection->cursor !== '' ? $connection->cursor : CarbonImmutable::now()->subYear()->toIso8601String();
        $checked = CarbonImmutable::now()->subMinutes(5)->toIso8601String(); // a small overlap: nothing completed at the edge is missed
        $counts = ['created' => 0, 'updated' => 0, 'skipped' => 0];

        $r = self::get($http, 'envelopes', ['from_date' => $since, 'status' => 'completed', 'include' => 'custom_fields', 'order_by' => 'status_changed',
            'order' => 'asc', 'count' => self::PER_RUN]);
        if ($r === null) {
            return $counts + ['cursor' => $since, 'note' => 'DocuSign asked us to slow down; carrying on shortly.', 'more' => false];
        }
        $envelopes = array_values(array_filter((array) $r->json('envelopes', []), 'is_array'));
        $unfiled = array_filter((array) ($connection->settings['unfiled'] ?? []), fn ($e) => is_array($e) && is_string($e['at'] ?? null)
            && CarbonImmutable::parse($e['at'])->gt(CarbonImmutable::now()->subDays(30)));
        foreach (array_slice(array_keys($unfiled), 0, 20) as $id) { // envelopes that named no deal before
            $e = self::get($http, 'envelopes/'.$id, ['include' => 'custom_fields'])?->json();
            if (is_array($e)) {
                $envelopes[] = $e;
            }
        }
        $last = $since;
        foreach ($envelopes as $e) {
            $id = is_string($e['envelopeId'] ?? null) ? $e['envelopeId'] : '';
            if ($id === '') {
                continue;
            }
            $deal = $this->dealFor($connection, $e);
            if ($deal === null) {
                $unfiled[$id] = ['subject' => mb_substr((string) ($e['emailSubject'] ?? ''), 0, 120), 'at' => $unfiled[$id]['at'] ?? CarbonImmutable::now()->toIso8601String()];
                $counts['skipped']++;
            } else {
                unset($unfiled[$id]);
                $name = trim((string) preg_replace('/^(please\s+)?docusign:\s*/i', '', (string) ($e['emailSubject'] ?? 'Signed documents'))) ?: 'Signed documents';
                $filed = $this->importer->document($connection, $deal, 'docusign:'.$id, $name.' (signed).pdf', function () use ($http, $id): ?string {
                    $pdf = (clone $http)->replaceHeaders(['Accept' => 'application/pdf'])->timeout(60)->get("envelopes/{$id}/documents/combined", ['certificate' => 'true']);

                    return $pdf->successful() ? $pdf->body() : null;
                });
                $counts[$filed ? 'created' : 'skipped']++;
            }
            if (is_string($e['statusChangedDateTime'] ?? $e['completedDateTime'] ?? null) && ($e['statusChangedDateTime'] ?? $e['completedDateTime']) > $last) {
                $last = (string) ($e['statusChangedDateTime'] ?? $e['completedDateTime']);
            }
        }
        $more = count(array_filter((array) $r->json('envelopes', []), 'is_array')) >= self::PER_RUN;
        $this->kit->saveSettings($connection, ['unfiled' => array_slice($unfiled, -200, null, true), 'interval_minutes' => 15]);
        $waiting = count($unfiled);

        return $counts + ['cursor' => $more ? $last : $checked, 'more' => $more,
            'note' => $waiting > 0 ? "{$waiting} signed envelope(s) don't name a deal's address yet. Put the address in the subject, or add the deal; they're filed automatically." : 'Up to date with DocuSign.'];
    }

    /**
     * The deal an envelope belongs to: its "RightAlly deal" custom field, or the one address its subject names.
     *
     * @param  array<string, mixed>  $e
     */
    private function dealFor(ConnectionData $connection, array $e): ?Deal
    {
        $fields = is_array($e['customFields'] ?? null) ? (array) ($e['customFields']['textCustomFields'] ?? []) : [];
        foreach ($fields as $f) {
            if (is_array($f) && ($f['name'] ?? null) === self::DEAL_FIELD && is_string($f['value'] ?? null)) {
                $deal = Deal::query()->where('ulid', trim($f['value']))->first();
                if ($deal !== null && ($connection->ownerUserId === null || $deal->agent_user_id === $connection->ownerUserId)) {
                    return $deal;
                }
            }
        }
        $id = BringOverFiles::match([(string) ($e['emailSubject'] ?? '')], BringOverFiles::addresses($connection->ownerUserId));

        return $id !== null ? Deal::query()->find($id) : null;
    }

    /**
     * The DocuSign account (and the server it lives on) the person signed in to, found once.
     *
     * @return array{0: string, 1: string}
     */
    private function account(ConnectionData $connection, string $token): array
    {
        $account = $connection->settings['account_id'] ?? null;
        $base = $connection->settings['base_uri'] ?? null;
        if (is_string($account) && is_string($base) && str_starts_with($base, 'https://')) {
            return [$account, $base];
        }
        $r = Http::withToken($token)->acceptJson()->timeout(15)->get(self::USERINFO);
        if (in_array($r->status(), [401, 403], true)) {
            throw new NeedsReconnect('DocuSign no longer accepts RightAlly\'s sign-in. Please connect it again.');
        }
        $accounts = array_values(array_filter((array) $r->json('accounts', []), 'is_array'));
        $chosen = collect($accounts)->first(fn (array $a): bool => ($a['is_default'] ?? false) === true) ?? ($accounts[0] ?? null);
        if (! is_array($chosen) || ! is_string($chosen['account_id'] ?? null) || ! is_string($chosen['base_uri'] ?? null) || ! str_starts_with($chosen['base_uri'], 'https://')) {
            throw new NeedsReconnect('That DocuSign login has no eSignature account. Please connect with the account you send envelopes from.');
        }
        $this->kit->saveSettings($connection, ['account_id' => $chosen['account_id'], 'base_uri' => $chosen['base_uri']]);

        return [$chosen['account_id'], $chosen['base_uri']];
    }

    /** @param  array<string, mixed>  $query */
    private static function get(PendingRequest $http, string $path, array $query = []): ?Response
    {
        $r = $http->get($path, $query);
        if ($r->status() === 429) {
            return null;
        }
        if (in_array($r->status(), [401, 403], true)) {
            throw new NeedsReconnect('DocuSign no longer accepts RightAlly\'s sign-in. Please connect it again.');
        }
        if ($r->status() === 404) {
            return null;
        }

        return $r->throw();
    }
}
