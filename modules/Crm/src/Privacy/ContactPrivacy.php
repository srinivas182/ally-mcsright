<?php

declare(strict_types=1);

namespace Modules\Crm\Privacy;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Modules\Core\Audit\AuditLogger;
use Modules\Core\Privacy\PrivacyRegistry;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\TenantContext;
use Modules\Crm\Models\Contact;
use Throwable;

/**
 * Contacts in privacy requests and retention (TR1, specs/core/18).
 * - Find: by email (any of the contact's addresses) or phone (last 10 digits).
 * - Copy: the contact's details, notes and activity, tasks, messages and consent history.
 * - Delete: names and contact details removed, notes, tasks, messages, memories and AI summaries deleted, the contact
 *   removed. The record of their texting consent is kept (proof the brokerage had permission for texts already sent),
 *   with the phone number removed from it.
 * - Stop selling or sharing: marketing email and texts switched off and recorded.
 * - Retention ("cold leads"): leads with no activity for the chosen months, never in a deal and not opted in.
 */
final class ContactPrivacy
{
    /** Tables holding a contact's personal history, deleted with it (consent events are kept on purpose). */
    private const HISTORY = ['crm_activities', 'crm_tasks', 'crm_memories', 'crm_contact_briefs', 'crm_ai_threads', 'crm_intents', 'crm_contact_scores', 'crm_contact_tag',
        'crm_household_members', 'crm_plan_enrollments', 'crm_ai_calls', 'crm_leads'];

    public static function register(PrivacyRegistry $registry): void
    {
        $registry->source('contacts', 'Contacts', self::find(...), self::export(...), self::erase(...), self::optOut(...));
        $registry->retention('cold_leads', 'Leads that went cold', 'Leads with no activity for this long, never in a deal and not opted in to marketing, are deleted. Off keeps them.',
            null, 24, self::purgeCold(...));
    }

    /** @return list<array{id: int, label: string}> */
    public static function find(string $email, ?string $phone): array
    {
        $tenant = app(TenantContext::class)->id();
        $digits = substr(preg_replace('/\D+/', '', (string) $phone) ?? '', -10);
        $email = mb_strtolower(trim($email));

        return array_values(Contact::query()->where('tenant_id', $tenant)
            ->where(function ($q) use ($email, $digits): void {
                $q->where('primary_email', $email)->orWhere('emails', 'like', '%'.str_replace(['%', '_'], ['\\%', '\\_'], $email).'%');
                if (strlen($digits) === 10) {
                    $q->orWhere('primary_phone', 'like', '%'.$digits);
                }
            })->limit(50)->get()
            ->map(fn (Contact $c): array => ['id' => (int) $c->id, 'label' => trim($c->first_name.' '.$c->last_name).' ('.($c->primary_email ?? $c->primary_phone ?? 'no email').')'])->all());
    }

    /** @return array<string, mixed> */
    public static function export(int $id): array
    {
        $c = Contact::query()->findOrFail($id);
        $db = DB::connection(Connections::cell());
        $conversations = $db->table('crm_conversations')->where('contact_id', $id)->pluck('id');

        return [
            'name' => trim($c->first_name.' '.$c->last_name), 'company' => $c->company_name, 'emails' => $c->getAttribute('emails'), 'phones' => $c->getAttribute('phones'),
            'address' => array_filter([$c->address_line1, $c->address_line2, $c->city, $c->state, $c->zip]), 'birthday' => $c->birthday?->toDateString(),
            'how_we_got_your_details' => $c->source, 'added_on' => $c->created_at?->toDateString(),
            'marketing' => ['email' => (bool) $c->email_opt_in, 'texts' => (bool) $c->sms_opt_in],
            'notes_and_activity' => $db->table('crm_activities')->where('contact_id', $id)->orderBy('id')->get(['type', 'subject', 'body', 'created_at'])->all(),
            'tasks' => $db->table('crm_tasks')->where('contact_id', $id)->orderBy('id')->get(['title', 'due_at', 'completed_at'])->all(),
            'messages' => $db->table('crm_messages')->whereIn('conversation_id', $conversations)->orderBy('id')->get(['direction', 'channel', 'body', 'created_at'])->all(),
            'consent_history' => $db->table('crm_consent_events')->where('contact_id', $id)->orderBy('id')->get(['channel', 'action', 'method', 'occurred_at'])->all(),
        ];
    }

    public static function erase(int $id): string
    {
        $db = DB::connection(Connections::cell());
        $conversations = $db->table('crm_conversations')->where('contact_id', $id)->pluck('id');
        self::quietly(fn () => $db->table('crm_messages')->whereIn('conversation_id', $conversations)->delete());
        self::quietly(fn () => $db->table('crm_conversations')->where('contact_id', $id)->delete());
        foreach (self::HISTORY as $table) {
            self::quietly(fn () => $db->table($table)->where('contact_id', $id)->delete());
        }
        self::quietly(fn () => $db->table('crm_relationships')->where('contact_id', $id)->orWhere('related_contact_id', $id)->delete());
        $db->table('crm_consent_events')->where('contact_id', $id)->update(['phone' => null, 'ip' => null, 'user_agent' => null]);
        $db->table('crm_contacts')->where('id', $id)->update(['first_name' => 'Deleted', 'last_name' => 'person', 'company_name' => null, 'emails' => '[]', 'phones' => '[]',
            'primary_email' => null, 'primary_phone' => null, 'search_name' => '', 'address_line1' => null, 'address_line2' => null, 'city' => null, 'zip' => null,
            'birthday' => null, 'source_detail' => null, 'email_opt_in' => false, 'sms_opt_in' => false, 'do_not_call' => true, 'deleted_at' => now(), 'updated_at' => now()]);
        app(AuditLogger::class)->record('crm.contact.erased', metadata: ['contact' => $id], severity: 'warning');

        return 'deleted';
    }

    public static function optOut(int $id): void
    {
        $db = DB::connection(Connections::cell());
        $db->table('crm_contacts')->where('id', $id)->update(['email_opt_in' => false, 'sms_opt_in' => false, 'sms_opt_out_at' => now(), 'updated_at' => now()]);
        foreach (['email', 'sms'] as $channel) {
            $db->table('crm_consent_events')->insert(['tenant_id' => app(TenantContext::class)->id(), 'contact_id' => $id, 'channel' => $channel, 'action' => 'opt_out',
                'method' => 'privacy_request', 'occurred_at' => now()]);
        }
    }

    public static function purgeCold(CarbonImmutable $cutoff): int
    {
        $tenant = app(TenantContext::class)->id();
        $removed = 0;
        Contact::query()->where('tenant_id', $tenant)->where('lifecycle', 'lead')->where('email_opt_in', false)->where('sms_opt_in', false)
            ->where(fn ($q) => $q->where('last_activity_at', '<', $cutoff)->orWhere(fn ($n) => $n->whereNull('last_activity_at')->where('created_at', '<', $cutoff)))
            ->select(['id'])->chunkById(200, function ($chunk) use (&$removed): void {
                foreach ($chunk as $c) {
                    if (app(PrivacyRegistry::class)->heldBecause('contacts', (int) $c->id) === null) {
                        self::erase((int) $c->id);
                        $removed++;
                    }
                }
            });

        return $removed;
    }

    private static function quietly(callable $fn): void
    {
        try {
            $fn();
        } catch (Throwable) {
            // a table this brokerage's database does not have yet
        }
    }
}
