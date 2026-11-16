<?php

declare(strict_types=1);

namespace Modules\Insights;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;
use Modules\Core\Access\Fields;
use Modules\Core\CustomFields\CustomFields;
use Modules\Core\Identity\Enums\UserKind;
use Modules\Crm\ContactVisibility;
use Modules\Crm\CrmAccess;
use Modules\Crm\Models\Lead;
use Modules\Transactions\DealAccess;
use Modules\Transactions\Models\Deal;

/**
 * The report builder (specs/insights/01, K2): pick what to report on, the columns, filters and a grouping. Every run
 * applies the viewer's own access: the deals, contacts, leads and agents they may see, with commission hidden from
 * roles without it, even on a report someone else shared.
 */
final class Reports
{
    public const MAX_ROWS = 5000;

    public const OPS = ['is' => 'is', 'is_not' => 'is not', 'over' => 'is over', 'under' => 'is under', 'from' => 'on or after', 'to' => 'on or before', 'contains' => 'contains', 'filled' => 'is filled in', 'empty' => 'is empty'];

    /**
     * What each subject offers: columns (a database column, or computed for agents), their type, and whether they are
     * hidden without commission access.
     *
     * @return array<string, array{label: string, columns: array<string, array{label: string, type: string, column?: string, money_hidden?: bool, options?: list<string>}>, default: list<string>}>
     */
    public static function subjects(): array
    {
        return [
            'deals' => ['label' => 'Deals', 'default' => ['street', 'agent', 'status', 'closed_on', 'price', 'gci'], 'columns' => [
                'street' => ['label' => 'Address', 'type' => 'text', 'column' => 'street'],
                'city' => ['label' => 'City', 'type' => 'text', 'column' => 'city'],
                'state' => ['label' => 'State', 'type' => 'text', 'column' => 'state_code'],
                'type' => ['label' => 'Type', 'type' => 'choice', 'column' => 'type', 'options' => ['buyer', 'listing', 'dual', 'lease', 'referral', 'new_construction']],
                'status' => ['label' => 'Status', 'type' => 'choice', 'column' => 'status', 'options' => ['pre_contract', 'under_contract', 'closed', 'fell_through', 'cancelled']],
                'agent' => ['label' => 'Agent', 'type' => 'person', 'column' => 'agent_user_id'],
                'price' => ['label' => 'Price', 'type' => 'money', 'column' => 'price_cents'],
                'gci' => ['label' => 'GCI', 'type' => 'money', 'column' => 'gci_cents', 'money_hidden' => true],
                'contract_date' => ['label' => 'Contract date', 'type' => 'date', 'column' => 'contract_date'],
                'closing_date' => ['label' => 'Expected closing', 'type' => 'date', 'column' => 'closing_date'],
                'closed_on' => ['label' => 'Closed on', 'type' => 'date', 'column' => 'closed_on'],
                'imported_from' => ['label' => 'Synced from', 'type' => 'text', 'column' => 'imported_from'],
            ]],
            'contacts' => ['label' => 'Contacts', 'default' => ['name', 'owner', 'lifecycle', 'source', 'last_activity'], 'columns' => [
                'name' => ['label' => 'Name', 'type' => 'text', 'column' => 'search_name'],
                'email' => ['label' => 'Email', 'type' => 'text', 'column' => 'primary_email'],
                'phone' => ['label' => 'Phone', 'type' => 'text', 'column' => 'primary_phone'],
                'city' => ['label' => 'City', 'type' => 'text', 'column' => 'city'],
                'state' => ['label' => 'State', 'type' => 'text', 'column' => 'state'],
                'owner' => ['label' => 'Agent', 'type' => 'person', 'column' => 'owner_user_id'],
                'origin' => ['label' => 'Company or personal', 'type' => 'choice', 'column' => 'origin', 'options' => ['company', 'personal']],
                'lifecycle' => ['label' => 'Lifecycle', 'type' => 'choice', 'column' => 'lifecycle', 'options' => ['lead', 'active', 'under_contract', 'past_client', 'sphere']],
                'source' => ['label' => 'Source', 'type' => 'text', 'column' => 'source'],
                'created' => ['label' => 'Added', 'type' => 'date', 'column' => 'created_at'],
                'last_activity' => ['label' => 'Last contact', 'type' => 'date', 'column' => 'last_activity_at'],
            ]],
            'leads' => ['label' => 'Leads', 'default' => ['created', 'source', 'agent', 'status', 'response_minutes', 'score'], 'columns' => [
                'created' => ['label' => 'Arrived', 'type' => 'date', 'column' => 'created_at'],
                'source' => ['label' => 'Source', 'type' => 'text', 'column' => 'source'],
                'channel' => ['label' => 'Channel', 'type' => 'text', 'column' => 'channel'],
                'status' => ['label' => 'Status', 'type' => 'text', 'column' => 'status'],
                'agent' => ['label' => 'Agent', 'type' => 'person', 'column' => 'assigned_user_id'],
                'response_minutes' => ['label' => 'Minutes to first reply', 'type' => 'number', 'column' => 'response_seconds'],
                'score' => ['label' => 'Score', 'type' => 'text', 'column' => 'score_label'],
                'zip' => ['label' => 'ZIP code', 'type' => 'text', 'column' => 'zip'],
            ]],
            'agents' => ['label' => 'Agents', 'default' => ['name', 'status', 'joined', 'sides_ytd', 'volume_ytd', 'gci_ytd'], 'columns' => [
                'name' => ['label' => 'Name', 'type' => 'text', 'column' => 'first_name'],
                'email' => ['label' => 'Email', 'type' => 'text', 'column' => 'email'],
                'status' => ['label' => 'Status', 'type' => 'choice', 'column' => 'status', 'options' => ['active', 'invited', 'suspended', 'deactivated']],
                'joined' => ['label' => 'Joined', 'type' => 'date', 'column' => 'created_at'],
                'sides_ytd' => ['label' => 'Closed sides this year', 'type' => 'number'],
                'volume_ytd' => ['label' => 'Volume this year', 'type' => 'money'],
                'gci_ytd' => ['label' => 'GCI this year', 'type' => 'money', 'money_hidden' => true],
            ]],
        ];
    }

    /**
     * Columns available for a subject, including the brokerage's custom fields.
     *
     * @return array<string, array{label: string, type: string, column?: string, money_hidden?: bool, options?: list<string>, custom?: string, hidden_from?: string|null}>
     */
    public function columns(string $subject): array
    {
        $cols = self::subjects()[$subject]['columns'] ?? [];
        $entity = ['deals' => 'deal', 'contacts' => 'contact', 'agents' => 'agent'][$subject] ?? null;
        if ($entity !== null) {
            foreach (app(CustomFields::class)->definitions($entity) as $f) {
                $cols['field.'.$f['key']] = ['label' => $f['label'], 'type' => in_array($f['type'], ['money', 'number', 'date'], true) ? $f['type'] : 'text', 'custom' => $entity,
                    'hidden_from' => $f['hidden_from']];
            }
        }

        return $cols;
    }

    /**
     * Runs a report definition for this person.
     *
     * @param  array<mixed>  $def  {subject, columns[], filters[{column, op, value}], group_by?}
     * @return array{columns: list<array{key: string, label: string, type: string}>, rows: list<array<string, mixed>>, groups: list<array{key: string, count: int, sums: array<string, int|float|null>}>|null, total: int, truncated: bool, hidden: list<string>}
     */
    public function run(User $viewer, array $def): array
    {
        $subject = (string) ($def['subject'] ?? '');
        $all = $this->columns($subject);
        if ($all === []) {
            throw ValidationException::withMessages(['subject' => 'Choose what to report on.']);
        }
        $chosen = array_values(array_filter(array_map('strval', (array) ($def['columns'] ?? [])), fn (string $c): bool => isset($all[$c])));
        $chosen = $chosen === [] ? self::subjects()[$subject]['default'] : array_slice($chosen, 0, 20);
        $money = Fields::visible($viewer, 'commission');
        $hidden = [];
        foreach ($chosen as $c) {
            if ((($all[$c]['money_hidden'] ?? false) && ! $money) || (($all[$c]['hidden_from'] ?? null) !== null && ! Fields::visible($viewer, (string) $all[$c]['hidden_from']))) {
                $hidden[] = $all[$c]['label'];
            }
        }
        $visible = array_values(array_filter($chosen, fn (string $c): bool => ! in_array($all[$c]['label'], $hidden, true)));

        $query = $this->base($viewer, $subject);
        foreach (array_slice((array) ($def['filters'] ?? []), 0, 10) as $f) {
            $this->filter($query, $all, (array) $f, $viewer, $money);
        }
        $total = (clone $query)->count();
        $models = $query->limit(self::MAX_ROWS)->get();
        $names = [];
        $customs = [];
        $entity = null;
        foreach ($visible as $c) {
            if (isset($all[$c]['custom'])) {
                $customs[$c] = substr($c, 6);
                $entity = $all[$c]['custom'];
            }
        }
        $ytd = $subject === 'agents' ? $this->agentYear(array_values($models->pluck('id')->map(fn ($v): int => (int) $v)->all())) : [];
        $dates = [];
        $rows = [];
        foreach ($models as $m) {
            $raw = $entity !== null ? app(CustomFields::class)->raw($entity, (int) $m->getKey()) : [];
            $row = ['_ulid' => (string) $m->getAttribute('ulid')];
            foreach ($visible as $c) {
                $row[$c] = match (true) {
                    isset($customs[$c]) => $raw[$customs[$c]] ?? null,
                    $subject === 'agents' && ! isset($all[$c]['column']) => $ytd[(int) $m->getKey()][$c] ?? 0,
                    $all[$c]['type'] === 'person' => $names[(int) $m->getAttribute((string) ($all[$c]['column'] ?? ''))] ??= (string) (User::query()->find((int) $m->getAttribute((string) ($all[$c]['column'] ?? '')))->name ?? ''),
                    $subject === 'agents' && $c === 'name' => $m instanceof User ? $m->name : '',
                    $subject === 'contacts' && $c === 'name' => trim($m->getAttribute('first_name').' '.$m->getAttribute('last_name')),
                    default => self::value($m, $all[$c]),
                };
            }
            $rows[] = $row;
            $month = str_starts_with((string) ($def['group_by'] ?? ''), 'month:') ? $m->getAttribute((string) ($all[substr((string) $def['group_by'], 6)]['column'] ?? '')) : null;
            $dates[] = $month !== null ? CarbonImmutable::parse((string) ($month instanceof \DateTimeInterface ? $month->format('Y-m-d') : $month))->format('Y-m') : 'No date';
        }

        $groups = null;
        $group = (string) ($def['group_by'] ?? '');
        if ($group !== '' && (in_array($group, $visible, true) || str_starts_with($group, 'month:'))) {
            $groups = [];
            $key = str_starts_with($group, 'month:') ? substr($group, 6) : $group;
            foreach ($rows as $i => $row) {
                $value = str_starts_with($group, 'month:') ? $dates[$i] : (string) ($row[$key] ?? '');
                $groups[$value] ??= ['key' => $value === '' ? '(blank)' : $value, 'count' => 0, 'sums' => []];
                $groups[$value]['count']++;
                foreach ($visible as $c) {
                    if (in_array($all[$c]['type'], ['money', 'number'], true) && is_numeric($row[$c] ?? null)) {
                        $groups[$value]['sums'][$c] = ($groups[$value]['sums'][$c] ?? 0) + $row[$c];
                    }
                }
            }
            $groups = array_values($groups);
            usort($groups, fn (array $a, array $b): int => $b['count'] <=> $a['count']);
        }

        return ['columns' => array_map(fn (string $c): array => ['key' => $c, 'label' => $all[$c]['label'], 'type' => $all[$c]['type']], $visible),
            'rows' => $rows, 'groups' => $groups, 'total' => $total, 'truncated' => $total > self::MAX_ROWS, 'hidden' => $hidden];
    }

    /**
     * @return Builder<covariant Model>
     */
    private function base(User $viewer, string $subject): Builder
    {
        $people = Numbers::people($viewer);

        return match ($subject) {
            'deals' => $people === false ? DealAccess::query($viewer)->orderByDesc('id') : Deal::query()->when($people !== null, fn (Builder $q) => $q->whereIn('agent_user_id', $people))->orderByDesc('id'),
            'contacts' => app(ContactVisibility::class)->query($viewer)->whereNull('merged_into_id')->orderByDesc('id'),
            'leads' => Lead::query()->when(! CrmAccess::manager($viewer), fn (Builder $q) => $q->where('assigned_user_id', $viewer->id))->orderByDesc('id'),
            'agents' => User::query()->where('kind', UserKind::Agent)->when($people === false, fn (Builder $q) => $q->whereKey($viewer->id))
                ->when(is_array($people), fn (Builder $q) => $q->whereIn('id', (array) $people))->orderBy('first_name'),
            default => throw ValidationException::withMessages(['subject' => 'Choose what to report on.']),
        };
    }

    /**
     * @param  Builder<covariant Model>  $query
     * @param  array<string, array<string, mixed>>  $all
     * @param  array<mixed>  $f
     */
    private function filter(Builder $query, array $all, array $f, User $viewer, bool $money): void
    {
        $key = (string) ($f['column'] ?? '');
        $op = (string) ($f['op'] ?? '');
        $spec = $all[$key] ?? null;
        if ($spec === null || ! array_key_exists($op, self::OPS) || (($spec['money_hidden'] ?? false) && ! $money)) {
            throw ValidationException::withMessages(['filters' => 'One of the filters can\'t be used here.']);
        }
        $value = $f['value'] ?? null;
        $type = (string) $spec['type'];
        if (isset($spec['custom'])) {
            $field = substr($key, 6);
            $sub = fn ($e) => $e->selectRaw('1')->from('custom_field_values as cfv')->join('custom_fields as cf', 'cf.id', '=', 'cfv.field_id')
                ->whereColumn('cfv.entity_id', $query->getModel()->getTable().'.id')->where('cf.entity', $spec['custom'])->where('cf.key', $field)
                ->when($op === 'is', fn ($w) => $w->where('cfv.value', (string) $value))->when($op === 'is_not', fn ($w) => $w->where('cfv.value', '!=', (string) $value))
                ->when($op === 'contains', fn ($w) => $w->where('cfv.value', 'like', '%'.$value.'%'))
                ->when($op === 'over', fn ($w) => $w->where('cfv.value', '>', (string) $value))->when($op === 'under', fn ($w) => $w->where('cfv.value', '<', (string) $value));
            $op === 'empty' ? $query->whereNotExists($sub) : $query->whereExists($sub);

            return;
        }
        if (! isset($spec['column'])) {
            throw ValidationException::withMessages(['filters' => "\"{$spec['label']}\" can be shown but not filtered on."]);
        }
        $col = (string) $spec['column'];
        if ($type === 'person' && is_string($value) && strlen($value) === 26) {
            $value = User::query()->where('ulid', $value)->value('id');
        }
        $scale = fn (mixed $v): mixed => $type === 'money' && is_numeric($v) ? (int) round((float) $v * 100) : ($key === 'response_minutes' && is_numeric($v) ? (int) $v * 60 : $v);
        match ($op) {
            'is' => $query->where($col, $scale($value)),
            'is_not' => $query->where(fn ($q) => $q->where($col, '!=', $scale($value))->orWhereNull($col)),
            'over' => $query->where($col, '>', $scale($value)),
            'under' => $query->where($col, '<', $scale($value)),
            'from' => $query->where($col, '>=', CarbonImmutable::parse((string) $value)->startOfDay()),
            'to' => $query->where($col, '<=', CarbonImmutable::parse((string) $value)->endOfDay()),
            'contains' => $query->where($col, 'like', '%'.str_replace(['%', '_'], ['\\%', '\\_'], mb_strtolower((string) $value)).'%'),
            'filled' => $query->whereNotNull($col)->where($col, '!=', ''),
            default => $query->where(fn ($q) => $q->whereNull($col)->orWhere($col, '')), // empty
        };
    }

    /**
     * @param  array<string, mixed>  $spec
     */
    private static function value(Model $m, array $spec): mixed
    {
        $v = $m->getAttribute((string) $spec['column']);
        if ($v instanceof \BackedEnum) {
            return $v->value;
        }
        if ($v instanceof \DateTimeInterface) {
            return CarbonImmutable::instance($v)->toDateString();
        }
        if (($spec['column'] ?? '') === 'response_seconds') {
            return $v !== null ? (int) round((int) $v / 60) : null;
        }

        return $v;
    }

    /**
     * @param  list<int>  $ids
     * @return array<int, array{sides_ytd: int, volume_ytd: int, gci_ytd: int}>
     */
    private function agentYear(array $ids): array
    {
        $out = [];
        $start = CarbonImmutable::today()->startOfYear()->toDateString();
        foreach (Deal::query()->where('status', 'closed')->where('closed_on', '>=', $start)->whereIn('agent_user_id', $ids)->get(['agent_user_id', 'price_cents', 'gci_cents']) as $d) {
            $out[$d->agent_user_id] ??= ['sides_ytd' => 0, 'volume_ytd' => 0, 'gci_ytd' => 0];
            $out[$d->agent_user_id]['sides_ytd']++;
            $out[$d->agent_user_id]['volume_ytd'] += $d->price_cents;
            $out[$d->agent_user_id]['gci_ytd'] += $d->gci_cents;
        }

        return $out;
    }
}
