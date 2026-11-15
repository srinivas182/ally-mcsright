<?php

declare(strict_types=1);

namespace Modules\Crm\Connect;

use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\Exceptions\InvalidFormatException;
use Illuminate\Validation\ValidationException;
use Modules\Core\Connect\ConnectionData;
use Modules\Core\Connect\ConnectorKit;
use Modules\Crm\Actions\ManageContacts;
use Modules\Crm\Actions\Tags;
use Modules\Crm\Actions\Timeline;
use Modules\Crm\Consent;
use Modules\Crm\ContactVisibility;
use Modules\Crm\CrmAccess;
use Modules\Crm\Models\Contact;

/**
 * The one set of rules every CRM import follows (specs/connect/04 and 06), whichever app the people come from:
 *
 * - Safe to repeat: each person and note is remembered by the other app's id, so nothing is added twice.
 * - No duplicates: someone already here with the same email or phone is linked, not copied (and not overwritten).
 * - Visibility: a contact the connecting person can't see is left alone.
 * - Acts as the person who connected the app, through the same services as the screens. A brokerage connection
 *   brings company contacts with their agent; an agent's own connection brings personal contacts.
 * - No texting consent comes across (TCPA): imported people are never texted until they opt in here.
 * - Opt-outs do come across (specs/grow/11): a "do not text / email / call" flag from the app, or a tag such as
 *   "DNC", "Do not text", "Opted out" or "Unsubscribed", stops those messages here too.
 */
final class Importer
{
    public function __construct(private readonly ConnectorKit $kit) {}

    /**
     * @param  array{id: string, first_name: string, last_name: string, email: string|null, phone: string|null, tags: list<string>, stage: string|null, source: string|null, agent_id: string|null, sms_opt_out?: bool, email_opt_out?: bool, do_not_call?: bool}  $p
     * @return 'created'|'updated'|'skipped'
     */
    public function person(ConnectionData $connection, User $by, array $p, string $app, string $stagePrefix): string
    {
        if ($p['id'] === '') {
            return 'skipped';
        }
        $known = $this->kit->localId($connection, 'contact', $p['id']);
        $contact = $known !== null ? Contact::query()->find($known) : null;
        $result = 'updated';
        if ($contact === null) {
            $contact = self::existing($p['email'], $p['phone']);
            if ($contact !== null && ! app(ContactVisibility::class)->canSee($by, $contact)) {
                self::optOuts($contact, $p, $app); // an opt-out is honoured even for someone else's contact

                return 'skipped'; // someone else's contact in the brokerage: left alone
            }
            $result = $contact !== null ? 'skipped' : 'created';
        }
        if ($contact === null) {
            $agent = $p['agent_id'] !== null ? $this->kit->localId($connection, 'user', $p['agent_id']) : null;
            try {
                $contact = app(ManageContacts::class)->create(array_filter(['first_name' => $p['first_name'], 'last_name' => $p['last_name'],
                    'email' => $p['email'], 'phone' => $p['phone'], 'source' => 'import', 'origin' => $connection->ownerUserId === null ? 'company' : 'personal',
                    'owner_user_id' => $connection->ownerUserId === null && CrmAccess::manager($by) ? ($agent ?? $by->id) : null], fn ($v) => $v !== null), $by);
            } catch (ValidationException) {
                return 'skipped'; // no name, or no email or phone we can use
            }
        }
        $this->kit->remember($connection, 'contact', $p['id'], $contact->id);
        if ($result === 'updated') {
            $names = array_filter(['first_name' => trim($p['first_name']), 'last_name' => trim($p['last_name'])], fn (string $v) => $v !== '');
            if ($names !== [] && array_diff_assoc($names, ['first_name' => $contact->first_name, 'last_name' => $contact->last_name]) !== []) {
                app(ManageContacts::class)->update($contact, $names, $by);
            }
        }
        $stageTag = $p['stage'] !== null && $p['stage'] !== '' ? mb_substr($stagePrefix.': '.$p['stage'], 0, 60) : null;
        if ($stageTag !== null) { // one stage at a time
            foreach ($contact->tags()->where('name', 'like', $stagePrefix.': %')->where('name', '!=', $stageTag)->get() as $old) {
                app(Tags::class)->detach($contact, $old);
            }
        }
        foreach (array_slice(array_values(array_filter([...$p['tags'], $stageTag], fn ($t) => is_string($t) && $t !== '')), 0, 20) as $tag) {
            app(Tags::class)->attach($contact, mb_substr($tag, 0, 60));
        }
        self::optOuts($contact, $p, $app);
        if ($result === 'created') {
            $contact->forceFill(['source_detail' => mb_substr($app.($p['source'] !== null && $p['source'] !== '' ? ': '.$p['source'] : ''), 0, 150)])->save();
        }

        return $result;
    }

    /**
     * @param  array{id: string, person_id: string, body: string, created: string|null}  $n
     * @return 'created'|'skipped'
     */
    public function note(ConnectionData $connection, User $by, array $n, string $app): string
    {
        $contactId = $n['person_id'] !== '' ? $this->kit->localId($connection, 'contact', $n['person_id']) : null;
        $contact = $contactId !== null ? Contact::query()->find($contactId) : null;
        if ($n['id'] === '' || $contact === null || $this->kit->localId($connection, 'note', $n['id']) !== null) {
            return 'skipped';
        }
        $body = trim(strip_tags($n['body']));
        if ($body === '') {
            return 'skipped';
        }
        try {
            $when = $n['created'] !== null ? CarbonImmutable::parse($n['created'], 'UTC')->utc() : null;
            $activity = app(Timeline::class)->log($contact, ['type' => 'note', 'body' => mb_substr('From '.$app.': '.$body, 0, 9000),
                'occurred_at' => $when !== null && ! $when->isFuture() ? $when->format('Y-m-d H:i:s') : null], $by);
        } catch (ValidationException|InvalidFormatException) {
            return 'skipped';
        }
        $this->kit->remember($connection, 'note', $n['id'], (int) $activity->id);

        return 'created';
    }

    /** The other app's users matched to RightAlly people by email (so contacts keep their agent). */
    public function user(ConnectionData $connection, string $externalId, string $email): void
    {
        $email = mb_strtolower(trim($email));
        $local = $externalId !== '' && $email !== '' ? User::query()->where('email', $email)->first() : null;
        if ($local instanceof User) {
            $this->kit->remember($connection, 'user', $externalId, $local->id);
        }
    }

    public static function existing(?string $email, ?string $phone): ?Contact
    {
        $email = $email !== null && trim($email) !== '' ? mb_strtolower(trim($email)) : null;
        $phone164 = $phone !== null ? ManageContacts::e164($phone) : null;
        if ($email === null && $phone164 === null) {
            return null;
        }

        return Contact::query()->where(fn ($q) => $q->when($email !== null, fn ($w) => $w->orWhere('primary_email', $email))
            ->when($phone164 !== null, fn ($w) => $w->orWhere('primary_phone', $phone164)))->orderBy('id')->first();
    }

    /** The tags that mean "stop": texts, emails, calls. */
    public const STOP_TAGS = [
        'sms' => '/^(dnc|do ?not ?(text|contact)|no ?(text|sms)|sms ?opt(ed)? ?out|opt(ed)? ?out|stop)$/i',
        'email' => '/^(unsubscribed|do ?not ?(email|contact)|no ?email|email ?opt(ed)? ?out|opt(ed)? ?out)$/i',
        'call' => '/^(dnc|do ?not ?(call|contact)|no ?calls?)$/i',
    ];

    /** @param  array<string, mixed>  $p */
    private static function optOuts(Contact $contact, array $p, string $app): void
    {
        $tags = array_map(fn ($t): string => trim((string) $t), (array) ($p['tags'] ?? []));
        $has = fn (string $kind): bool => array_filter($tags, fn (string $t): bool => preg_match(self::STOP_TAGS[$kind], $t) === 1) !== [];
        $sms = ($p['sms_opt_out'] ?? false) === true || $has('sms');
        $email = ($p['email_opt_out'] ?? false) === true || $has('email');
        $call = ($p['do_not_call'] ?? false) === true || $has('call');
        if ($sms || $email || $call) {
            app(Consent::class)->fromConnectedApp($contact, $app, $sms, $email, $call);
        }
    }
}
