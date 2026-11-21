<?php

declare(strict_types=1);

namespace Modules\Listings\Idx;

use App\Models\User;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Modules\Core\Audit\AuditLogger;
use Modules\Core\Identity\Enums\UserKind;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\Models\Tenant;
use Modules\Core\Tenancy\TenantContext;
use Modules\Forms\Library\FieldMapper;
use Modules\Forms\Library\FillableFields;
use Modules\Forms\Pdf;
use stdClass;
use Throwable;

/**
 * IDX12: the board's own agreement, filled in. Most MLS agreements are fillable PDFs; their fields are found with qpdf
 * (already used for every form RightAlly imports), placed where the board put them, and matched to what RightAlly
 * already knows (firm name, MLS ID, license, managing broker, website…). HQ checks the match once per board. The
 * brokerage then sees the form filled in, completes or corrects anything, previews it, and sends it for signature.
 * The board's signature lines are signed in RightAlly; our own summary page is added only when the form has none.
 */
final class BoardForm
{
    /** What a field can be filled with: RightAlly's details, the brokerage typing it in, or nothing. */
    public const SOURCES = [
        'brokerage_name' => 'Brokerage (firm) name',
        'website' => 'Website address',
        'member_id' => 'MLS member ID',
        'office_id' => 'Office ID',
        'license_number' => 'Real estate license number',
        'managing_broker_name' => 'Managing broker name',
        'managing_broker_email' => 'Managing broker email',
        'owner_name' => "Owner's name",
        'owner_email' => "Owner's email",
        'vendor' => 'Technology provider (RightAlly)',
        'today' => "Today's date",
        'fill' => 'The brokerage fills it in',
        'skip' => 'Leave blank',
    ];

    /** Words in a field's name => what fills it. First match wins, so more specific words come first. */
    private const MATCHES = [
        '/\b(broker|participant|principal|designated|managing)\b.*\b(email|e mail)\b|\b(email|e mail)\b.*\b(broker|participant)\b/' => 'managing_broker_email',
        '/\b(email|e mail)\b/' => 'owner_email',
        '/\b(website|web site|url|domain|idx site)\b/' => 'website',
        '/\b(office|firm|brokerage|company) (id|code|number|no|mls id)\b/' => 'office_id',
        '/\b(member|agent|participant|subscriber|public|nrds|mls|login) (mls )?(id|number|no|code)\b|\bmls id\b/' => 'member_id',
        '/\blicen[cs]e\b|\blic\b/' => 'license_number',
        '/\b(broker|participant|principal|designated|managing)\b.*\bname\b|\b(designated|managing|principal) broker\b/' => 'managing_broker_name',
        '/\b(firm|brokerage|company|office|business)\b.*\bname\b|\b(firm|brokerage|company)\b/' => 'brokerage_name',
        '/\b(vendor|technology provider|provider)\b(?!.*\b(contact|phone|email)\b)/' => 'vendor',
        '/\bdate\b/' => 'today',
    ];

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * Finds the fillable fields in the board's PDF and suggests what fills each one.
     *
     * @return list<array{key: string, label: string, type: string, page: int, x: float, y: float, w: float, h: float, source: string|null, role: string|null, required: bool}>
     */
    public static function detect(string $pdf): array
    {
        try {
            [, , $sizes] = Pdf::normalise($pdf);
        } catch (Throwable) {
            return [];
        }
        $out = [];
        foreach (FieldMapper::map(FillableFields::read($pdf), $sizes) as $f) {
            $words = FieldMapper::words((string) $f['label']);
            $signer = in_array($f['type'], ['signature', 'initials', 'date_signed'], true);
            $role = null;
            $source = null;
            if ($signer) {
                $role = preg_match('/\b(broker|participant|principal|designated|managing)\b/', $words) === 1 ? 'managing_broker' : 'brokerage';
            } elseif ($f['type'] === 'checkbox') {
                $source = 'fill';
            } else {
                $source = 'fill';
                foreach (self::MATCHES as $pattern => $fact) {
                    if (preg_match($pattern, $words) === 1) {
                        $source = $fact;
                        break;
                    }
                }
            }
            $out[] = ['key' => (string) $f['key'], 'label' => (string) $f['label'], 'type' => (string) $f['type'], 'page' => (int) $f['page'], 'x' => (float) $f['x'],
                'y' => (float) $f['y'], 'w' => (float) $f['w'], 'h' => (float) $f['h'], 'source' => $source, 'role' => $role, 'required' => $source === 'fill'];
        }

        return $out;
    }

    /**
     * HQ saves what fills each field (labels, positions and types stay as found).
     *
     * @param  list<array<string, mixed>>  $input
     */
    public function save(string $boardUlid, array $input, User $by): void
    {
        $board = $this->db()->table('idx_boards')->where('ulid', $boardUlid)->first();
        if (! $board instanceof stdClass) {
            throw ValidationException::withMessages(['board' => 'That board no longer exists.']);
        }
        $found = collect(self::fields($board))->keyBy('key');
        $saved = [];
        foreach ($input as $row) {
            $f = $found->get((string) ($row['key'] ?? ''));
            if ($f === null) {
                continue;
            }
            $signer = in_array($f['type'], ['signature', 'initials', 'date_signed'], true);
            $source = (string) ($row['source'] ?? '');
            $role = (string) ($row['role'] ?? '');
            if ($signer && ! in_array($role, ['brokerage', 'managing_broker'], true)) {
                throw ValidationException::withMessages(['fields' => 'Choose who signs "'.$f['label'].'".']);
            }
            if (! $signer && ! array_key_exists($source, self::SOURCES)) {
                throw ValidationException::withMessages(['fields' => 'Choose what fills "'.$f['label'].'".']);
            }
            $label = trim(mb_substr(strip_tags((string) ($row['label'] ?? '')), 0, 80));
            $saved[] = array_merge($f, ['label' => $label !== '' ? $label : $f['label'], 'source' => $signer ? null : $source, 'role' => $signer ? $role : null,
                'required' => ! $signer && $source === 'fill' && (bool) ($row['required'] ?? true)]);
        }
        $this->db()->table('idx_boards')->where('id', $board->id)->update(['agreement_fields' => json_encode($saved), 'agreement_fields_at' => now(), 'updated_by' => $by->id,
            'updated_at' => now()]);
        $this->audit->record('idx.board.form_fields', metadata: ['board' => $board->code, 'fields' => count($saved), 'by' => $by->id], severity: 'notice');
    }

    /** Called when the board's form changes: find the fields again (HQ checks them before the next brokerage signs). */
    public function refresh(stdClass $board): int
    {
        $path = (string) ($board->agreement_path ?? '');
        $fields = $path !== '' && Storage::disk('local')->exists($path) ? self::detect((string) Storage::disk('local')->get($path)) : [];
        $this->db()->table('idx_boards')->where('id', $board->id)->update(['agreement_fields' => $fields !== [] ? json_encode($fields) : null, 'agreement_fields_at' => null]);

        return count($fields);
    }

    /**
     * The board's fields (as HQ saved them, or as found).
     *
     * @return list<array{key: string, label: string, type: string, page: int, x: float, y: float, w: float, h: float, source: string|null, role: string|null, required: bool}>
     */
    public static function fields(stdClass $board): array
    {
        $fields = json_decode((string) ($board->agreement_fields ?? '[]'), true);
        $out = [];
        foreach (is_array($fields) ? $fields : [] as $f) {
            if (is_array($f) && isset($f['key'], $f['type'], $f['page'])) {
                $out[] = ['key' => (string) $f['key'], 'label' => (string) ($f['label'] ?? $f['key']), 'type' => (string) $f['type'], 'page' => (int) $f['page'],
                    'x' => (float) ($f['x'] ?? 0), 'y' => (float) ($f['y'] ?? 0), 'w' => (float) ($f['w'] ?? 0), 'h' => (float) ($f['h'] ?? 0),
                    'source' => isset($f['source']) ? (string) $f['source'] : null, 'role' => isset($f['role']) ? (string) $f['role'] : null, 'required' => (bool) ($f['required'] ?? false)];
            }
        }

        return $out;
    }

    /** True when the board's own form is filled in (it has fields HQ has checked). */
    public static function inUse(stdClass $board): bool
    {
        return ($board->agreement_fields_at ?? null) !== null && self::fields($board) !== [];
    }

    /**
     * What RightAlly fills in for this brokerage, merged with anything it already typed or corrected.
     *
     * @return array<string, string>
     */
    public static function values(stdClass $board, stdClass $sub): array
    {
        $tenant = Tenant::query()->whereKey((int) $sub->tenant_id)->first();
        $owner = $tenant !== null ? app(TenantContext::class)->runAs($tenant, fn () => User::query()->where('kind', UserKind::Owner)->orderBy('id')->first()) : null;
        $facts = ['brokerage_name' => (string) ($tenant->name ?? ''), 'website' => (string) ($sub->website ?: ($tenant !== null ? app(Agreements::class)->website((int) $tenant->id) : '')),
            'member_id' => (string) $sub->member_id, 'office_id' => (string) ($sub->office_id ?? ''), 'license_number' => (string) $sub->license_number,
            'managing_broker_name' => (string) $sub->managing_broker_name, 'managing_broker_email' => (string) $sub->managing_broker_email,
            'owner_name' => (string) ($owner->name ?? ''), 'owner_email' => (string) ($owner->email ?? ''), 'vendor' => 'RightAlly', 'today' => now()->format('m/d/Y')];
        $typed = (array) json_decode((string) ($sub->form_values ?? '[]'), true);
        $out = [];
        foreach (self::fields($board) as $f) {
            if ($f['source'] === null || $f['source'] === 'skip') {
                continue;
            }
            $out[$f['key']] = array_key_exists($f['key'], $typed) ? (string) $typed[$f['key']] : ($facts[$f['source']] ?? '');
        }

        return $out;
    }

    /**
     * The brokerage's review: its values for the fields it may fill or correct. Returns what is still missing.
     *
     * @param  array<string, mixed>  $input
     * @return list<string> labels of required fields left empty
     */
    public static function clean(stdClass $board, array $input): array
    {
        $missing = [];
        foreach (self::fields($board) as $f) {
            if ($f['source'] === null || $f['source'] === 'skip') {
                continue;
            }
            $v = trim(mb_substr(strip_tags((string) ($input[$f['key']] ?? '')), 0, 300));
            if ($f['required'] && $v === '') {
                $missing[] = $f['label'];
            }
        }

        return $missing;
    }

    /** A preview of the filled form (no signatures yet), for the brokerage to check before signing. */
    public static function preview(stdClass $board, stdClass $sub): ?string
    {
        $path = (string) ($board->agreement_path ?? '');
        if ($path === '' || ! Storage::disk('local')->exists($path)) {
            return null;
        }
        [$pdf] = Pdf::normalise((string) Storage::disk('local')->get($path));
        $values = self::values($board, $sub);
        $stamps = [];
        foreach (self::fields($board) as $f) {
            if (isset($values[$f['key']])) {
                $stamps[] = ['page' => $f['page'], 'x' => $f['x'], 'y' => $f['y'], 'w' => $f['w'], 'h' => $f['h'],
                    'text' => $f['type'] === 'checkbox' ? (in_array(strtolower($values[$f['key']]), ['1', 'yes', 'x', 'true', 'on'], true) ? 'X' : '') : $values[$f['key']]];
            }
        }

        return Pdf::fill($pdf, $stamps);
    }

    private function db(): ConnectionInterface
    {
        return DB::connection(Connections::control());
    }
}
