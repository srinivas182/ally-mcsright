<?php

declare(strict_types=1);

namespace Modules\Movewise;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use Modules\Core\Audit\AuditLogger;
use Modules\Core\Tenancy\Enums\TenantType;
use Modules\Core\Tenancy\Models\Tenant;
use Modules\Listings\Access\MlsAccess;
use Modules\Listings\Models\MlsAuthorization;
use Modules\Listings\Models\MlsSource;
use Modules\Movewise\Models\Partner;
use Modules\Movewise\Models\State;

/**
 * Movewise states and partners (specs/movewise/01, MW1).
 *
 * - Each launch state has its own switch, a managing broker partner (the licensed broker of record there) and the
 *   legal checklist of specs/movewise/00 §12. A state can be switched on only when the checklist is complete, its
 *   partner is active with a signed contract, and an MLS feed covering the state is live.
 * - Listings come from MLS feeds licensed to HQ under each partner's broker MLS id: they are HQ's own feeds (the
 *   HQ account holds the MLS authorisations), never a brokerage's.
 * - Switching a state off stops it showing at once; nothing already shown is changed.
 */
final class Movewise
{
    /** The legal checklist (specs/movewise/00 §12), each signed off in the HQ console. */
    public const CHECKLIST = [
        'partner_agreement' => 'Managing broker agreement signed (referral split, supervision, responsibility for the site in the state)',
        'notices' => 'Required state notices on the site (Texas: TREC Information About Brokerage Services and Consumer Protection Notice)',
        'referral_rules' => 'Referral-fee and lead-fee rules confirmed by counsel',
        'respa' => 'RESPA: no lender, title or insurance company pays Movewise',
        'tcpa' => 'TCPA and state telemarketing: consent wording reviewed, any texting registration done',
        'mls_rules' => 'MLS display rules and attribution agreed with the partner',
        'privacy' => 'Privacy notice covers how data moves to the chosen agent',
    ];

    /** The HQ account whose MLS authorisations are Movewise's feeds. */
    public function feedTenant(): ?Tenant
    {
        return Tenant::query()->where('type', TenantType::Hq)->orderBy('id')->first();
    }

    /** @return list<string> the states switched on, e.g. ['TX'] */
    public function onStates(): array
    {
        return array_values(State::query()->where('on', true)->orderBy('code')->pluck('code')->map(fn ($c): string => (string) $c)->all());
    }

    /** @return list<MlsSource> live MLS feeds for a state */
    public function feeds(string $state): array
    {
        $tenant = $this->feedTenant();
        if ($tenant === null) {
            return [];
        }
        $live = app(MlsAccess::class)->licensedSources($tenant->id);

        return array_values(MlsSource::query()->whereIn('id', $live)->get()->filter(fn (MlsSource $s): bool => in_array($state, $s->states, true))->all());
    }

    /** @return list<string> what is still missing before a state can be switched on */
    public function missing(State $state): array
    {
        $out = [];
        $p = $state->partner;
        if ($p === null) {
            $out[] = 'Choose the managing broker partner';
        } elseif ($p->status !== 'active' || $p->contract_signed_on === null) {
            $out[] = 'The partner must be active, with the contract signed';
        } elseif ($p->licence_state !== $state->code) {
            $out[] = 'The partner must be licensed in '.$state->name;
        }
        foreach (self::CHECKLIST as $key => $label) {
            if (($state->checklist[$key]['done'] ?? false) !== true) {
                $out[] = $label;
            }
        }
        if ($this->feeds($state->code) === []) {
            $out[] = 'A live MLS feed covering '.$state->name;
        }

        return $out;
    }

    public function switchState(State $state, bool $on, User $by): void
    {
        if ($on && ($missing = $this->missing($state)) !== []) {
            throw ValidationException::withMessages(['state' => $state->name.' can\'t be switched on yet: '.implode('; ', $missing).'.']);
        }
        $state->forceFill(['on' => $on] + ($on ? ['turned_on_at' => CarbonImmutable::now(), 'turned_on_by' => $by->id] : []))->save();
        app(AuditLogger::class)->record($on ? 'movewise.state_on' : 'movewise.state_off', metadata: ['state' => $state->code], severity: 'notice');
    }

    public function check(State $state, string $item, bool $done, ?string $note, User $by): void
    {
        abort_unless(isset(self::CHECKLIST[$item]), 404);
        $list = $state->checklist ?? [];
        $list[$item] = ['done' => $done, 'note' => $note !== null ? mb_substr(trim($note), 0, 300) : null, 'by' => $by->id, 'at' => CarbonImmutable::now()->toIso8601String()];
        $state->forceFill(['checklist' => $list])->save();
        if (! $done && $state->on) {
            $this->switchState($state, false, $by); // an item taken back switches the state off
        }
        app(AuditLogger::class)->record('movewise.checklist', metadata: ['state' => $state->code, 'item' => $item, 'done' => $done], severity: 'notice');
    }

    /** @param  array<string, mixed>  $in */
    public function savePartner(?Partner $partner, array $in, User $by): Partner
    {
        $clean = fn (string $k, int $max): ?string => is_string($in[$k] ?? null) && trim($in[$k]) !== '' ? mb_substr(trim(strip_tags($in[$k])), 0, $max) : null;
        $data = ['name' => $clean('name', 160), 'broker_name' => $clean('broker_name', 120), 'licence_number' => $clean('licence_number', 40),
            'licence_state' => strtoupper((string) $clean('licence_state', 2)), 'phone' => $clean('phone', 30), 'email' => $clean('email', 150), 'address' => $clean('address', 200),
            'iabs_url' => $clean('iabs_url', 500), 'contract_signed_on' => $clean('contract_signed_on', 10), 'status' => ($in['status'] ?? 'active') === 'inactive' ? 'inactive' : 'active'];
        $errors = array_filter([
            'name' => $data['name'] === null ? 'Enter the brokerage\'s licensed name.' : null,
            'broker_name' => $data['broker_name'] === null ? 'Enter the managing broker\'s name.' : null,
            'licence_number' => $data['licence_number'] === null ? 'Enter the brokerage licence number.' : null,
            'licence_state' => ! array_key_exists($data['licence_state'], (array) config('movewise.launch_states')) ? 'Choose a launch state.' : null,
            'email' => $data['email'] !== null && filter_var($data['email'], FILTER_VALIDATE_EMAIL) === false ? 'Enter a valid email.' : null,
            'iabs_url' => $data['iabs_url'] !== null && ! str_starts_with($data['iabs_url'], 'https://') ? 'Use an https:// link.' : null,
        ]);
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
        $p = $partner ?? new Partner;
        $p->fill($data)->save();
        if ($p->status === 'inactive') {
            foreach (State::query()->where('partner_id', $p->id)->where('on', true)->get() as $s) {
                $this->switchState($s, false, $by); // no broker of record: the state goes off
            }
        }
        app(AuditLogger::class)->record('movewise.partner', metadata: ['partner' => $p->ulid], severity: 'notice');

        return $p;
    }

    public function assign(State $state, ?Partner $partner, User $by): void
    {
        if ($partner !== null && $partner->licence_state !== $state->code) {
            throw ValidationException::withMessages(['partner' => 'The partner must be licensed in '.$state->name.'.']);
        }
        $state->forceFill(['partner_id' => $partner?->id])->save();
        if ($partner === null && $state->on) {
            $this->switchState($state, false, $by);
        }
    }

    /** Asks for an MLS feed for Movewise under the partner's broker MLS id (approved in HQ → MLS, like any feed). */
    public function requestFeed(State $state, MlsSource $source, string $brokerMlsId, User $by): MlsAuthorization
    {
        $tenant = $this->feedTenant() ?? throw ValidationException::withMessages(['mls' => 'There is no HQ account to hold Movewise\'s feeds.']);
        if (! in_array($state->code, $source->states, true)) {
            throw ValidationException::withMessages(['mls' => $source->name.' doesn\'t cover '.$state->name.'.']);
        }

        return app(MlsAccess::class)->request($tenant->id, $source, $brokerMlsId, [], 'Movewise '.$state->code.($state->partner !== null ? ' via '.$state->partner->name : ''), $by);
    }
}
