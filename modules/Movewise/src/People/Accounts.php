<?php

declare(strict_types=1);

namespace Modules\Movewise\People;

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Modules\Core\Support\Connections;
use Modules\Movewise\Mail\SignInCode;
use Modules\Movewise\Models\Person;
use Symfony\Component\HttpFoundation\Cookie;

/**
 * Passwordless Movewise accounts (specs/movewise/02): an email and a 6-digit code, nothing else. A code lasts 10
 * minutes and allows 5 tries. The browser keeps a random token in a first-party, HttpOnly cookie; only its hash is
 * stored. Signing out, or deleting the account, ends it.
 */
final class Accounts
{
    public const COOKIE = 'mw_sid';

    private const DAYS = 90;

    private ?Person $current = null;

    /** The cookie the cached person was looked up for, so one worker never reuses another visitor's answer. */
    private ?string $resolvedFor = null;

    public function start(string $email, string $locale): void
    {
        $email = mb_strtolower(trim($email));
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false || mb_strlen($email) > 190) {
            throw ValidationException::withMessages(['email' => $locale === 'es' ? 'Escribe un correo válido.' : 'Enter a valid email address.']);
        }
        $db = DB::connection(Connections::control())->table('movewise_sign_ins');
        if ($db->where('email', $email)->where('created_at', '>=', CarbonImmutable::now()->subHour())->count() >= 5) {
            throw ValidationException::withMessages(['email' => $locale === 'es' ? 'Demasiados códigos. Inténtalo en una hora.' : 'Too many codes. Try again in an hour.']);
        }
        $code = (string) random_int(100000, 999999);
        $db->insert(['email' => $email, 'code_hash' => hash('sha256', $code), 'attempts' => 0, 'expires_at' => CarbonImmutable::now()->addMinutes(10), 'created_at' => CarbonImmutable::now()]);
        Mail::to($email)->send(new SignInCode($code, $locale));
    }

    /** @return array{person: Person, cookie: Cookie} */
    public function verify(string $email, string $code, string $locale): array
    {
        $email = mb_strtolower(trim($email));
        $code = (string) preg_replace('/\D/', '', $code);
        $db = DB::connection(Connections::control())->table('movewise_sign_ins');
        $row = $db->where('email', $email)->where('expires_at', '>', CarbonImmutable::now())->where('attempts', '<', 5)->orderByDesc('id')->first();
        if ($row === null || ! hash_equals((string) $row->code_hash, hash('sha256', $code))) {
            if ($row !== null) {
                $db->where('id', $row->id)->increment('attempts');
            }
            throw ValidationException::withMessages(['code' => $locale === 'es' ? 'Ese código no es correcto o ya venció.' : "That code isn't right, or it has expired."]);
        }
        $db->where('email', $email)->delete();
        $person = Person::query()->firstOrCreate(['email' => $email], ['locale' => $locale]);
        $person->forceFill(['email_verified_at' => $person->email_verified_at ?? CarbonImmutable::now(), 'last_seen_at' => CarbonImmutable::now()])->save();
        $token = bin2hex(random_bytes(32));
        DB::connection(Connections::control())->table('movewise_sessions')->insert(['token_hash' => hash('sha256', $token), 'person_id' => $person->id,
            'expires_at' => CarbonImmutable::now()->addDays(self::DAYS), 'created_at' => CarbonImmutable::now()]);

        return ['person' => $person, 'cookie' => new Cookie(self::COOKIE, $token, CarbonImmutable::now()->addDays(self::DAYS), '/', null, true, true, false, Cookie::SAMESITE_LAX)];
    }

    /** The signed-in person on this request, if any. */
    public function current(Request $request): ?Person
    {
        $token = (string) $request->cookies->get(self::COOKIE, '');
        if ($this->resolvedFor === $token) {
            return $this->current;
        }
        $this->resolvedFor = $token;
        $this->current = null;
        if (strlen($token) !== 64) {
            return null;
        }
        $row = DB::connection(Connections::control())->table('movewise_sessions')->where('token_hash', hash('sha256', $token))->where('expires_at', '>', CarbonImmutable::now())->first();

        return $this->current = $row !== null ? Person::query()->whereKey($row->person_id)->first() : null;
    }

    public function signOut(Request $request): Cookie
    {
        $token = (string) $request->cookies->get(self::COOKIE, '');
        $this->resolvedFor = null;
        $this->current = null;
        DB::connection(Connections::control())->table('movewise_sessions')->where('token_hash', hash('sha256', $token))->delete();

        return new Cookie(self::COOKIE, '', 1, '/', null, true, true, false, Cookie::SAMESITE_LAX);
    }

    /** @return array<string, mixed> everything Movewise holds about this person */
    public function export(Person $p): array
    {
        $db = DB::connection(Connections::control());

        return ['account' => ['email' => $p->email, 'name' => $p->name, 'language' => $p->locale, 'created_at' => $p->created_at->toIso8601String()],
            'saved_homes' => $db->table('movewise_saved_homes')->where('person_id', $p->id)->pluck('listing_ulid')->all(),
            'saved_searches' => $db->table('movewise_saved_searches')->where('person_id', $p->id)->get(['name', 'filters', 'frequency'])->map(fn ($r): array => ['name' => $r->name,
                'filters' => json_decode((string) $r->filters, true), 'alerts' => $r->frequency])->all(),
            'move_plan' => $p->plan, 'conversations' => $db->table('movewise_chats')->where('person_id', $p->id)->pluck('messages')->map(fn ($m): mixed => json_decode((string) $m, true))->all()];
    }

    /** Deletes the person and everything about them (sessions, saves, alerts, plan, conversations). */
    public function delete(Person $p): void
    {
        $db = DB::connection(Connections::control());
        $db->transaction(function () use ($db, $p): void {
            foreach (['movewise_sessions', 'movewise_saved_homes', 'movewise_saved_searches', 'movewise_chats'] as $t) {
                $db->table($t)->where('person_id', $p->id)->delete();
            }
            $db->table('movewise_sign_ins')->where('email', $p->email)->delete();
            $p->delete();
        });
    }
}
