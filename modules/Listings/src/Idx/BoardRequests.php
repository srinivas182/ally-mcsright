<?php

declare(strict_types=1);

namespace Modules\Listings\Idx;

use App\Models\User;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Modules\Core\Audit\AuditLogger;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\Host;
use Modules\Core\Tenancy\Models\Tenant;
use Modules\Listings\Idx\Mail\IdxUpdateMail;
use stdClass;
use Throwable;

/**
 * IDX8: "My board isn't listed". A brokerage names its MLS board; HQ adds it to the directory (hidden until it has a
 * connector and a fee) or closes the request with a reason. Everyone who asked is emailed the day the board opens.
 */
final class BoardRequests
{
    public const OPEN_LIMIT = 10;

    public function __construct(private readonly AuditLogger $audit) {}

    /** @param array<string, mixed> $input */
    public function ask(int $tenantId, array $input, User $by): string
    {
        $data = Validator::make($input, [
            'name' => ['required', 'string', 'min:3', 'max:120'],
            'state' => ['required', Rule::in($this->db()->table('idx_states')->pluck('code')->all())],
            'website' => ['nullable', 'url:https,http', 'max:255'],
            'note' => ['nullable', 'string', 'max:500'],
        ], ['state.in' => 'Choose the state.'])->validate();
        $mine = $this->db()->table('idx_board_requests')->where('tenant_id', $tenantId)->where('status', 'open');
        if ($mine->clone()->count() >= self::OPEN_LIMIT) {
            throw ValidationException::withMessages(['name' => 'You already have '.self::OPEN_LIMIT.' boards waiting. We will email you as each one opens.']);
        }
        $name = trim((string) $data['name']);
        $same = $mine->clone()->where('state', $data['state'])->whereRaw('lower(name) = ?', [mb_strtolower($name)])->value('ulid');
        if (is_string($same)) {
            return $same;
        }
        $ulid = (string) Str::ulid();
        $this->db()->table('idx_board_requests')->insert(['ulid' => $ulid, 'tenant_id' => $tenantId, 'name' => $name, 'state' => $data['state'],
            'website' => $data['website'] ?? null, 'note' => $data['note'] ?? null, 'email' => $by->email, 'status' => 'open', 'created_by' => $by->id,
            'created_at' => now(), 'updated_at' => now()]);
        $this->audit->record('idx.board.requested', metadata: ['name' => $name, 'state' => $data['state']]);

        return $ulid;
    }

    /**
     * A brokerage's own requests (never anyone else's).
     *
     * @return list<array<string, mixed>>
     */
    public function forTenant(int $tenantId): array
    {
        return array_values($this->db()->table('idx_board_requests')->where('tenant_id', $tenantId)->orderByDesc('id')->limit(20)->get()
            ->map(fn (stdClass $r): array => ['ulid' => (string) $r->ulid, 'name' => (string) $r->name, 'state' => (string) $r->state, 'status' => (string) $r->status,
                'hq_note' => $r->hq_note, 'asked' => substr((string) $r->created_at, 0, 10)])->all());
    }

    /**
     * HQ: every request, open first, with the brokerage's name and how many brokerages asked for the same board.
     *
     * @return list<array<string, mixed>>
     */
    public function queue(): array
    {
        $rows = $this->db()->table('idx_board_requests')->orderByRaw("case when status = 'open' then 0 else 1 end")->orderByDesc('id')->limit(200)->get();
        $names = Tenant::query()->whereIn('id', $rows->pluck('tenant_id')->unique()->all())->pluck('name', 'id');
        $asks = $rows->where('status', 'open')->countBy(fn (stdClass $r): string => $r->state.'|'.mb_strtolower((string) $r->name));

        return array_values($rows->map(fn (stdClass $r): array => ['ulid' => (string) $r->ulid, 'name' => (string) $r->name, 'state' => (string) $r->state, 'website' => $r->website,
            'note' => $r->note, 'brokerage' => (string) ($names[$r->tenant_id] ?? 'A brokerage'), 'status' => (string) $r->status, 'hq_note' => $r->hq_note,
            'same' => (int) ($asks[$r->state.'|'.mb_strtolower((string) $r->name)] ?? 0), 'asked' => substr((string) $r->created_at, 0, 10)])->all());
    }

    /**
     * HQ adds the requested board to the directory, hidden until it has a connector and a fee. Other open requests for
     * the same board are linked to it too. Returns the new board's ulid.
     */
    public function add(string $ulid, string $code, User $by): string
    {
        $request = $this->find($ulid);
        $data = Validator::make(['code' => strtoupper(trim($code))], [
            'code' => ['required', 'string', 'max:20', 'regex:/^[A-Z0-9_-]+$/', Rule::unique((Connections::control() !== null ? Connections::control().'.' : '').'idx_boards', 'code')],
        ], ['code.regex' => 'Use capital letters, numbers, - or _ (for example HAR).'])->validate();
        $boardUlid = (string) Str::ulid();
        $boardId = (int) $this->db()->table('idx_boards')->insertGetId(['ulid' => $boardUlid, 'code' => $data['code'], 'name' => $request->name, 'state' => $request->state,
            'other_states' => '[]', 'visibility' => 'hidden', 'setup_fee_cents' => 0, 'checked' => false,
            'notes' => Str::limit('Asked for by a brokerage.'.($request->website !== null ? ' '.$request->website : ''), 500, ''), 'updated_by' => $by->id,
            'created_at' => now(), 'updated_at' => now()]);
        $this->db()->table('idx_board_requests')->where('status', 'open')->where('state', $request->state)->whereRaw('lower(name) = ?', [mb_strtolower((string) $request->name)])
            ->update(['status' => 'added', 'board_id' => $boardId, 'updated_at' => now()]);
        $this->audit->record('idx.board.added', metadata: ['board' => $data['code'], 'from_request' => $ulid, 'by' => $by->id], severity: 'notice');

        return $boardUlid;
    }

    /** HQ closes a request (for example a board we cannot connect yet), with a reason the brokerage sees. */
    public function close(string $ulid, string $note, User $by): void
    {
        $request = $this->find($ulid);
        $note = trim($note);
        if ($note === '' || mb_strlen($note) > 500) {
            throw ValidationException::withMessages(['note' => 'Say in a sentence why, so the brokerage knows.']);
        }
        $this->db()->table('idx_board_requests')->where('id', $request->id)->update(['status' => 'closed', 'hq_note' => $note, 'updated_at' => now()]);
        $this->audit->record('idx.board.request_closed', metadata: ['request' => $ulid, 'by' => $by->id]);
        $this->email($request, 'About the MLS board you asked for', ['You asked us to add '.$request->name.'.', $note]);
    }

    /** Called when a board becomes public: email everyone who asked for it, once. */
    public function boardOpened(int $boardId): int
    {
        $rows = $this->db()->table('idx_board_requests')->where('board_id', $boardId)->whereNull('notified_at')->get();
        foreach ($rows as $request) {
            $this->email($request, $request->name.' is ready for your website', ['You asked us to add '.$request->name.'. It is ready now.',
                'Open Settings › MLS and choose "Add board" to start. Your website shows sample listings until the MLS approves.']);
        }
        $this->db()->table('idx_board_requests')->whereIn('id', $rows->pluck('id')->all())->update(['notified_at' => now()]);

        return $rows->count();
    }

    /** @param list<string> $lines */
    private function email(stdClass $request, string $heading, array $lines): void
    {
        try {
            $tenant = Tenant::query()->whereKey((int) $request->tenant_id)->first();
            if ($tenant !== null && filter_var($request->email, FILTER_VALIDATE_EMAIL) !== false) {
                Mail::to((string) $request->email)->queue(new IdxUpdateMail($heading, $lines, 'Open Settings › MLS', 'https://'.Host::platformHostFor($tenant->slug).'/settings/mls'));
            }
        } catch (Throwable) {
            // An email that cannot go out never stops HQ's work.
        }
    }

    private function find(string $ulid): stdClass
    {
        $request = $this->db()->table('idx_board_requests')->where('ulid', $ulid)->where('status', 'open')->first();
        if ($request === null) {
            throw ValidationException::withMessages(['request' => 'That request has already been handled.']);
        }

        return $request;
    }

    private function db(): Connection
    {
        return DB::connection(Connections::control());
    }
}
