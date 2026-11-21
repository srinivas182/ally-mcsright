<?php

declare(strict_types=1);

namespace Modules\Listings\Idx;

use App\Models\User;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Modules\Core\Audit\AuditLogger;
use Modules\Core\Support\Connections;

/**
 * IDX1: IDX coverage. HQ opens states, keeps the MLS board directory and the data connectors. A board's client fee is
 * what HQ sets, or else our cost on the main connector plus that connector's margin. Brokers and agents only ever see
 * board names, regions, approval times and fees, never connector names.
 */
final class Coverage
{
    public const VISIBILITY = ['public', 'coming_soon', 'hidden'];

    public const CONNECTOR_STATUS = ['active', 'maintenance', 'disabled'];

    public function __construct(private readonly AuditLogger $audit) {}

    private function db(): ConnectionInterface
    {
        return DB::connection(Connections::control());
    }

    /** @return list<array{code: string, name: string, enabled: bool, boards: int, public: int}> */
    public function states(): array
    {
        $counts = $this->db()->table('idx_boards')->selectRaw("state, count(*) as total, sum(case when visibility = 'public' then 1 else 0 end) as open")->groupBy('state')->get()->keyBy('state');

        return array_values($this->db()->table('idx_states')->orderBy('name')->get()->map(fn (object $s): array => ['code' => (string) $s->code, 'name' => (string) $s->name,
            'enabled' => (bool) $s->enabled, 'boards' => (int) ($counts[$s->code]->total ?? 0), 'public' => (int) ($counts[$s->code]->open ?? 0)])->all());
    }

    /** @return list<string> */
    public function enabledStates(): array
    {
        return array_values($this->db()->table('idx_states')->where('enabled', true)->orderBy('name')->pluck('code')->map(fn ($c): string => (string) $c)->all());
    }

    public function setState(string $code, bool $enabled, User $by): void
    {
        $changed = $this->db()->table('idx_states')->where('code', strtoupper($code))->update(['enabled' => $enabled, 'updated_at' => now()]);
        if ($changed === 0) {
            throw ValidationException::withMessages(['state' => 'Unknown state.']);
        }
        $this->audit->record('idx.state.'.($enabled ? 'opened' : 'closed'), metadata: ['state' => strtoupper($code), 'by' => $by->id], severity: 'notice');
    }

    /** @return list<array<string, mixed>> */
    public function connectors(): array
    {
        return array_values($this->db()->table('idx_connectors')->orderBy('name')->get()->map(fn (object $c): array => ['key' => (string) $c->key, 'name' => (string) $c->name,
            'kind' => (string) $c->kind, 'status' => (string) $c->status, 'margin_percent' => (int) $c->margin_percent, 'notes' => $c->notes])->all());
    }

    /** @param array<string, mixed> $input */
    public function saveConnector(string $key, array $input, User $by): void
    {
        $data = Validator::make($input, [
            'status' => ['required', Rule::in(self::CONNECTOR_STATUS)],
            'margin_percent' => ['required', 'integer', 'min:0', 'max:500'],
            'notes' => ['nullable', 'string', 'max:500'],
        ])->validate();
        $changed = $this->db()->table('idx_connectors')->where('key', $key)->update([...$data, 'updated_at' => now()]);
        if ($changed === 0) {
            throw ValidationException::withMessages(['connector' => 'Unknown connector.']);
        }
        $this->audit->record('idx.connector.updated', metadata: ['connector' => $key, 'status' => $data['status'], 'margin_percent' => $data['margin_percent'], 'by' => $by->id], severity: 'notice');
    }

    /**
     * Boards, optionally only those in one state (a board shows under its main state and any other states it covers).
     *
     * @return list<array<string, mixed>>
     */
    public function boards(?string $state = null): array
    {
        $margins = $this->db()->table('idx_connectors')->pluck('margin_percent', 'key');
        $statuses = $this->db()->table('idx_connectors')->pluck('status', 'key');
        $q = $this->db()->table('idx_boards')->orderBy('state')->orderBy('name');

        return array_values($q->get()->filter(fn (object $b): bool => $state === null || $b->state === $state || in_array($state, (array) json_decode((string) ($b->other_states ?? '[]'), true), true))
            ->map(fn (object $b): array => [
                'ulid' => (string) $b->ulid, 'code' => (string) $b->code, 'name' => (string) $b->name, 'state' => (string) $b->state,
                'other_states' => (array) json_decode((string) ($b->other_states ?? '[]'), true), 'region' => $b->region, 'visibility' => (string) $b->visibility,
                'approval_days_min' => (int) $b->approval_days_min, 'approval_days_max' => (int) $b->approval_days_max,
                'main_connector' => $b->main_connector, 'main_cost_cents' => $b->main_cost_cents !== null ? (int) $b->main_cost_cents : null,
                'backup_connector' => $b->backup_connector, 'backup_cost_cents' => $b->backup_cost_cents !== null ? (int) $b->backup_cost_cents : null,
                'connector_code' => $b->connector_code, 'monthly_fee_cents' => $b->monthly_fee_cents !== null ? (int) $b->monthly_fee_cents : null,
                'fee_cents' => self::fee($b->monthly_fee_cents, $b->main_cost_cents, $b->main_connector !== null ? ($margins[$b->main_connector] ?? null) : null),
                'setup_fee_cents' => (int) $b->setup_fee_cents, 'rules_profile_id' => $b->rules_profile_id !== null ? (int) $b->rules_profile_id : null,
                'mls_source_id' => $b->mls_source_id !== null ? (int) $b->mls_source_id : null, 'checked' => (bool) $b->checked, 'notes' => $b->notes,
                'ready' => $b->main_connector !== null && ($statuses[$b->main_connector] ?? null) === 'active',
                // IDX6: the agreement workflow.
                'has_agreement_pdf' => is_string($b->agreement_path ?? null) && $b->agreement_path !== '', 'agreement_text' => $b->agreement_text ?? null,
                'managing_broker_signs' => (bool) ($b->managing_broker_signs ?? true), 'send_to' => $b->send_to ?? null, 'send_cc' => $b->send_cc ?? null, 'send_bcc' => $b->send_bcc ?? null,
            ])->all());
    }

    /**
     * Boards a broker or agent can choose: public, in an open state, with a fee. Never shows connector names.
     *
     * @param  list<string>  $states
     * @return list<array{ulid: string, code: string, name: string, state: string, region: string|null, fee_cents: int, setup_fee_cents: int, approval: string}>
     */
    public function offered(array $states): array
    {
        $open = array_values(array_intersect(array_map('strtoupper', $states), $this->enabledStates()));
        $out = [];
        foreach ($this->boards() as $b) {
            $covers = array_merge([$b['state']], $b['other_states']);
            if ($b['visibility'] !== 'public' || $b['fee_cents'] === null || array_intersect($covers, $open) === []) {
                continue;
            }
            $out[] = ['ulid' => $b['ulid'], 'code' => $b['code'], 'name' => $b['name'], 'state' => $b['state'], 'region' => $b['region'], 'fee_cents' => $b['fee_cents'],
                'setup_fee_cents' => $b['setup_fee_cents'], 'approval' => $b['approval_days_min'].'–'.$b['approval_days_max'].' business days'];
        }

        return $out;
    }

    /** IDX6: HQ uploads the MLS's own agreement (PDF) for a board; a signature page is added when it is sent. */
    public function saveAgreementPdf(string $ulid, string $bytes, User $by): void
    {
        $board = $this->db()->table('idx_boards')->where('ulid', $ulid)->first();
        if ($board === null) {
            throw ValidationException::withMessages(['board' => 'That board no longer exists.']);
        }
        if (! str_starts_with($bytes, '%PDF-')) {
            throw ValidationException::withMessages(['file' => 'Upload the agreement as a PDF.']);
        }
        $path = 'idx/agreement-templates/'.$board->code.'-'.now()->format('YmdHis').'.pdf';
        Storage::disk('local')->put($path, $bytes);
        $this->db()->table('idx_boards')->where('id', $board->id)->update(['agreement_path' => $path, 'updated_by' => $by->id, 'updated_at' => now()]);
        $this->audit->record('idx.board.agreement_uploaded', metadata: ['board' => $board->code, 'by' => $by->id], severity: 'notice');
    }

    /** The client's monthly fee: what HQ set, else our cost plus the connector's margin, else none yet. */
    public static function fee(mixed $set, mixed $cost, mixed $margin): ?int
    {
        if ($set !== null) {
            return (int) $set;
        }
        if ($cost === null || $margin === null) {
            return null;
        }

        return (int) (ceil(((int) $cost) * (100 + (int) $margin) / 100 / 100) * 100); // rounded up to whole dollars
    }

    /**
     * Adds a board (no ulid) or changes one.
     *
     * @param  array<string, mixed>  $input
     */
    public function saveBoard(?string $ulid, array $input, User $by): string
    {
        $connectors = $this->db()->table('idx_connectors')->pluck('key')->all();
        $states = $this->db()->table('idx_states')->pluck('code')->all();
        $existing = $ulid !== null ? $this->db()->table('idx_boards')->where('ulid', $ulid)->first() : null;
        if ($ulid !== null && $existing === null) {
            throw ValidationException::withMessages(['board' => 'That board no longer exists.']);
        }
        $data = Validator::make($input, [
            'code' => ['required', 'string', 'max:20', 'regex:/^[A-Z0-9_-]+$/', Rule::unique((Connections::control() !== null ? Connections::control().'.' : '').'idx_boards', 'code')->ignore($existing?->id)],
            'name' => ['required', 'string', 'max:120'],
            'state' => ['required', Rule::in($states)],
            'other_states' => ['present', 'array', 'max:10'],
            'other_states.*' => [Rule::in($states)],
            'region' => ['nullable', 'string', 'max:120'],
            'visibility' => ['required', Rule::in(self::VISIBILITY)],
            'approval_days_min' => ['required', 'integer', 'min:0', 'max:90'],
            'approval_days_max' => ['required', 'integer', 'gte:approval_days_min', 'max:90'],
            'main_connector' => ['nullable', Rule::in($connectors)],
            'main_cost_cents' => ['nullable', 'integer', 'min:0', 'max:10000000'],
            'backup_connector' => ['nullable', Rule::in($connectors), 'different:main_connector'],
            'backup_cost_cents' => ['nullable', 'integer', 'min:0', 'max:10000000'],
            'connector_code' => ['nullable', 'string', 'max:60', 'regex:/^[A-Za-z0-9._-]+$/'],
            'monthly_fee_cents' => ['nullable', 'integer', 'min:0', 'max:10000000'],
            'setup_fee_cents' => ['required', 'integer', 'min:0', 'max:10000000'],
            'rules_profile_id' => ['nullable', 'integer'],
            'checked' => ['required', 'boolean'],
            'notes' => ['nullable', 'string', 'max:500'],
            'agreement_text' => ['nullable', 'string', 'max:5000'],
            'managing_broker_signs' => ['sometimes', 'boolean'],
            'send_to' => ['nullable', 'string', 'max:500'],
            'send_cc' => ['nullable', 'string', 'max:500'],
            'send_bcc' => ['nullable', 'string', 'max:500'],
        ], ['code.regex' => 'Use capital letters, numbers, - or _ (for example HAR).', 'backup_connector.different' => 'The backup must be a different connector.'])->validate();

        foreach (['send_to', 'send_cc', 'send_bcc'] as $list) {
            $typed = array_filter(preg_split('/[\s,;]+/', (string) ($data[$list] ?? '')) ?: []);
            if (count($typed) !== count(Agreements::emails((string) ($data[$list] ?? '')))) {
                throw ValidationException::withMessages([$list => 'Use email addresses separated by commas.']);
            }
            $data[$list] = $typed !== [] ? implode(', ', Agreements::emails((string) $data[$list])) : null;
        }
        // A board is only offered to brokers once it has a connector and a fee: say so rather than hide it quietly.
        if ($data['visibility'] === 'public' && ($data['main_connector'] ?? null) === null) {
            throw ValidationException::withMessages(['visibility' => 'Choose a main connector before making this board public.']);
        }
        $row = [...$data, 'other_states' => json_encode(array_values(array_unique(array_diff($data['other_states'], [$data['state']])))), 'updated_by' => $by->id, 'updated_at' => now()];
        if ($existing === null) {
            $ulid = (string) Str::ulid();
            $this->db()->table('idx_boards')->insert([...$row, 'ulid' => $ulid, 'created_at' => now()]);
            $this->audit->record('idx.board.added', metadata: ['board' => $data['code'], 'by' => $by->id], severity: 'notice');
        } else {
            $this->db()->table('idx_boards')->where('id', $existing->id)->update($row);
            $changes = [];
            foreach (['main_connector', 'monthly_fee_cents', 'visibility'] as $field) {
                if (($existing->{$field} ?? null) != ($data[$field] ?? null)) {
                    $changes[$field] = ['old' => $existing->{$field} ?? null, 'new' => $data[$field] ?? null];
                }
            }
            $this->audit->record('idx.board.updated', changes: $changes, metadata: ['board' => $data['code'], 'by' => $by->id], severity: 'notice');
            // IDX8: brokerages that asked for this board hear the day it opens.
            if ($data['visibility'] === 'public' && $existing->visibility !== 'public') {
                app(BoardRequests::class)->boardOpened((int) $existing->id);
            }
        }

        return (string) $ulid;
    }
}
