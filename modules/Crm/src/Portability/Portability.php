<?php

declare(strict_types=1);

namespace Modules\Crm\Portability;

use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Modules\Core\Audit\AuditLogger;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\Host;
use Modules\Core\Tenancy\TenantContext;
use stdClass;

/**
 * The portability guarantee (specs/grow/03, SO1): an agent's own people (personal contacts they own) are theirs. They
 * can download them at any time, and when they leave, they are emailed a private link (30 days, five downloads) to take
 * them along. Company contacts, from the brokerage's own website, ads and lead sources, stay with the brokerage.
 *
 * The file uses the same column names as RightAlly's spreadsheet import, so it goes straight into a new RightAlly.
 */
final class Portability
{
    public const COLUMNS = ['first_name', 'last_name', 'company_name', 'email', 'phone', 'address_line1', 'city', 'state', 'zip', 'birthday', 'tags', 'notes', 'last_contact'];

    public const LINK_DAYS = 30;

    public const MAX_DOWNLOADS = 5;

    public function __construct(private readonly TenantContext $tenants) {}

    /** How many people would come along. */
    public function count(User $agent): int
    {
        return $this->contacts($agent)->count();
    }

    /** The agent's own people as a spreadsheet (CSV) the RightAlly import reads directly. */
    public function csv(User $agent, string $why): string
    {
        $cell = DB::connection(Connections::cell());
        $out = fopen('php://temp', 'r+');
        if ($out === false) {
            return '';
        }
        fputcsv($out, self::COLUMNS, escape: '');
        $count = 0;
        $this->contacts($agent)->orderBy('id')->chunk(500, function ($rows) use ($cell, $out, &$count): void {
            $ids = $rows->pluck('id')->all();
            $tags = $cell->table('crm_contact_tag')->join('crm_tags', 'crm_tags.id', '=', 'crm_contact_tag.tag_id')->whereIn('crm_contact_tag.contact_id', $ids)
                ->get(['crm_contact_tag.contact_id', 'crm_tags.name'])->groupBy('contact_id');
            $notes = $cell->table('crm_activities')->whereIn('contact_id', $ids)->where('type', 'note')->orderBy('occurred_at')
                ->get(['contact_id', 'body', 'occurred_at'])->groupBy('contact_id');
            foreach ($rows as $c) {
                /** @var stdClass $c */
                $emails = array_values(array_filter((array) json_decode((string) $c->emails, true), 'is_string'));
                $phones = array_values(array_filter((array) json_decode((string) $c->phones, true), 'is_string'));
                $noteText = collect($notes->get($c->id, []))->map(fn ($n): string => substr((string) $n->occurred_at, 0, 10).': '.trim((string) $n->body))->implode("\n");
                fputcsv($out, [
                    self::safe((string) $c->first_name), self::safe((string) $c->last_name), self::safe((string) ($c->company_name ?? '')),
                    self::safe((string) ($c->primary_email ?? ($emails[0] ?? ''))), self::safe((string) ($c->primary_phone ?? ($phones[0] ?? ''))),
                    self::safe((string) ($c->address_line1 ?? '')), self::safe((string) ($c->city ?? '')), (string) ($c->state ?? ''), (string) ($c->zip ?? ''),
                    (string) ($c->birthday ?? ''), self::safe(collect($tags->get($c->id, []))->pluck('name')->implode(', ')), self::safe($noteText),
                    $c->last_activity_at !== null ? substr((string) $c->last_activity_at, 0, 10) : '',
                ], escape: '');
                $count++;
            }
        });
        rewind($out);
        $csv = (string) stream_get_contents($out);
        fclose($out);
        app(AuditLogger::class)->record('crm.portability.downloaded', metadata: ['agent' => $agent->id, 'people' => $count, 'how' => $why], severity: 'notice');

        return $csv;
    }

    /** Called when an agent is deactivated: email them a private link to their own people, if they have any. */
    public function offer(User $agent): void
    {
        if ($agent->email === '' || $this->count($agent) === 0) {
            return;
        }
        $token = Str::random(40);
        DB::connection(Connections::cell())->table('crm_portability_links')->insert(['tenant_id' => $this->tenants->id(), 'user_id' => $agent->id,
            'token_hash' => hash('sha256', $token), 'expires_at' => now()->addDays(self::LINK_DAYS), 'created_at' => now(), 'updated_at' => now()]);
        $url = 'https://'.$this->host().'/take-my-people/'.$token;
        Notification::route('mail', $agent->email)->notify(new TakeYourPeopleMail($agent->first_name, $this->tenants->current()->name, $this->count($agent), $url));
        app(AuditLogger::class)->record('crm.portability.offered', metadata: ['agent' => $agent->id], severity: 'notice');
    }

    /** The agent a private link belongs to, if it still works. */
    public function peek(string $token): ?User
    {
        $link = $this->link($token);

        return $link !== null ? User::query()->find((int) $link->user_id) : null;
    }

    /** The same, counting a download. */
    public function redeem(string $token): ?User
    {
        $link = $this->link($token);
        $agent = $link !== null ? User::query()->find((int) $link->user_id) : null;
        if ($link !== null && $agent !== null) {
            DB::connection(Connections::cell())->table('crm_portability_links')->where('id', $link->id)->increment('downloads');
        }

        return $agent;
    }

    private function link(string $token): ?stdClass
    {
        $link = DB::connection(Connections::cell())->table('crm_portability_links')->where('token_hash', hash('sha256', $token))->where('tenant_id', $this->tenants->id())->first();

        return $link !== null && now()->lte($link->expires_at) && (int) $link->downloads < self::MAX_DOWNLOADS ? $link : null;
    }

    /** @return Builder */
    private function contacts(User $agent)
    {
        return DB::connection(Connections::cell())->table('crm_contacts')->where('tenant_id', $this->tenants->id())->where('owner_user_id', $agent->id)
            ->where('origin', 'personal')->whereNull('deleted_at');
    }

    /** Spreadsheet programs run cells that start with = + - @ as formulas: those get a leading apostrophe. */
    private static function safe(string $value): string
    {
        return $value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true) ? "'".$value : $value;
    }

    private function host(): string
    {
        $tenant = $this->tenants->current();
        $primary = $tenant->domains()->where('is_primary', true)->value('host');

        return is_string($primary) && $primary !== '' ? $primary : Host::platformHostFor($tenant->slug);
    }
}
