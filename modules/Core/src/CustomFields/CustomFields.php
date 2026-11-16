<?php

declare(strict_types=1);

namespace Modules\Core\CustomFields;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Core\Access\Can;
use Modules\Core\Access\Fields;
use Modules\Core\Audit\AuditLogger;
use Modules\Core\Support\Connections;

/**
 * Custom fields on contacts, deals and agents (specs/core/10, N1). A brokerage adds the fields it needs; each one
 * has a type, can be required before a deal closes, and can be hidden from roles without a permission (the R1 field
 * groups). Values are checked by type and every change is audited.
 */
final class CustomFields
{
    public const ENTITIES = ['contact' => 'Contacts', 'deal' => 'Deals', 'agent' => 'Agents'];

    public const TYPES = ['text' => 'Text', 'number' => 'Number', 'money' => 'Money ($)', 'date' => 'Date', 'choice' => 'One choice',
        'multi' => 'Several choices', 'yesno' => 'Yes or no', 'url' => 'Link'];

    public const MAX_PER_ENTITY = 50;

    /** @var array<string, list<array{ulid: string, key: string, label: string, type: string, options: list<string>, required_to_close: bool, hidden_from: string|null, help: string|null, archived: bool}>> */
    private array $memo = [];

    public function __construct(private readonly AuditLogger $audit) {}

    public static function manages(User $user): bool
    {
        return Can::manage($user, 'core.fields.manage');
    }

    /**
     * @return list<array{ulid: string, key: string, label: string, type: string, options: list<string>, required_to_close: bool, hidden_from: string|null, help: string|null, archived: bool}>
     */
    public function definitions(string $entity, bool $withArchived = false): array
    {
        if (! $withArchived && isset($this->memo[$entity])) {
            return $this->memo[$entity];
        }
        $list = array_values(DB::connection(Connections::cell())->table('custom_fields')->where('entity', $entity)->when(! $withArchived, fn ($q) => $q->whereNull('archived_at'))
            ->orderBy('sort')->orderBy('id')->get()->map(fn (\stdClass $f): array => self::shape($f))->all());

        return $withArchived ? $list : $this->memo[$entity] = $list;
    }

    /**
     * @param  array{label: string, type: string, options?: list<string>|null, required_to_close?: bool, hidden_from?: string|null, help?: string|null}  $data
     */
    public function define(string $entity, array $data, User $by, ?string $ulid = null): string
    {
        $this->guard($by);
        if (! array_key_exists($entity, self::ENTITIES)) {
            throw ValidationException::withMessages(['entity' => 'Fields can be added to contacts, deals or agents.']);
        }
        $label = trim($data['label']);
        if ($label === '' || mb_strlen($label) > 80) {
            throw ValidationException::withMessages(['label' => 'Give the field a name (up to 80 characters).']);
        }
        if (! array_key_exists($data['type'], self::TYPES)) {
            throw ValidationException::withMessages(['type' => 'Choose a field type.']);
        }
        $options = array_values(array_unique(array_filter(array_map(fn ($o): string => mb_substr(trim((string) $o), 0, 60), (array) ($data['options'] ?? [])), fn (string $o): bool => $o !== '')));
        if (in_array($data['type'], ['choice', 'multi'], true) && count($options) < 2) {
            throw ValidationException::withMessages(['options' => 'Give at least two choices.']);
        }
        $hidden = $data['hidden_from'] ?? null;
        if ($hidden !== null && ! array_key_exists($hidden, Fields::HIDEABLE)) {
            throw ValidationException::withMessages(['hidden_from' => 'Unknown field group.']);
        }
        $row = ['label' => $label, 'type' => $data['type'], 'options' => in_array($data['type'], ['choice', 'multi'], true) ? json_encode(array_slice($options, 0, 50)) : null,
            'required_to_close' => $entity === 'deal' && (bool) ($data['required_to_close'] ?? false), 'hidden_from' => $hidden,
            'help' => isset($data['help']) && trim((string) $data['help']) !== '' ? mb_substr(trim((string) $data['help']), 0, 200) : null, 'updated_at' => CarbonImmutable::now()];
        $db = DB::connection(Connections::cell());
        if ($ulid !== null) {
            $field = $db->table('custom_fields')->where('ulid', $ulid)->where('entity', $entity)->first() ?? abort(404);
            if ($field->type !== $data['type'] && $db->table('custom_field_values')->where('field_id', $field->id)->exists()) {
                throw ValidationException::withMessages(['type' => 'This field already has values, so its type can\'t change. Add a new field instead.']);
            }
            $db->table('custom_fields')->where('id', $field->id)->update($row);
        } else {
            if ($db->table('custom_fields')->where('entity', $entity)->whereNull('archived_at')->count() >= self::MAX_PER_ENTITY) {
                throw ValidationException::withMessages(['label' => 'You have the most fields allowed here ('.self::MAX_PER_ENTITY.'). Archive one first.']);
            }
            $key = Str::slug($label, '_') ?: 'field';
            $base = mb_substr($key, 0, 50);
            for ($i = 2; $db->table('custom_fields')->where('entity', $entity)->where('key', $key)->exists(); $i++) {
                $key = $base.'_'.$i;
            }
            $ulid = (string) Str::ulid();
            $db->table('custom_fields')->insert($row + ['ulid' => $ulid, 'tenant_id' => $by->tenant_id, 'entity' => $entity, 'key' => $key,
                'sort' => (int) $db->table('custom_fields')->where('entity', $entity)->max('sort') + 1, 'created_by' => $by->id, 'created_at' => CarbonImmutable::now()]);
        }
        $this->memo = [];
        $this->audit->record('core.custom_field.saved', $by, metadata: ['entity' => $entity, 'label' => $label, 'type' => $data['type']], label: $label);

        return $ulid;
    }

    public function archive(string $entity, string $ulid, User $by, bool $archived = true): void
    {
        $this->guard($by);
        $this->memo = [];
        DB::connection(Connections::cell())->table('custom_fields')->where('entity', $entity)->where('ulid', $ulid)->update(['archived_at' => $archived ? CarbonImmutable::now() : null]);
        $this->audit->record($archived ? 'core.custom_field.archived' : 'core.custom_field.restored', $by, metadata: ['entity' => $entity, 'field' => $ulid]);
    }

    /**
     * The fields and values on one record, as this person may see them.
     *
     * @return list<array{key: string, label: string, type: string, options: list<string>, required_to_close: bool, help: string|null, value: string|list<string>|bool|null, hidden: bool}>
     */
    public function valuesFor(string $entity, int $id, User $viewer, ?int $ownerUserId = null): array
    {
        $values = $this->raw($entity, $id);
        $out = [];
        foreach ($this->definitions($entity) as $f) {
            $hidden = $f['hidden_from'] !== null && ! Fields::visible($viewer, $f['hidden_from'], $ownerUserId);
            $out[] = ['key' => $f['key'], 'label' => $f['label'], 'type' => $f['type'], 'options' => $f['options'], 'required_to_close' => $f['required_to_close'],
                'help' => $f['help'], 'value' => $hidden ? null : self::decode($f['type'], $values[$f['key']] ?? null), 'hidden' => $hidden];
        }

        return $out;
    }

    /**
     * @return array<string, string> key => stored value
     */
    public function raw(string $entity, int $id): array
    {
        return DB::connection(Connections::cell())->table('custom_field_values as v')->join('custom_fields as f', 'f.id', '=', 'v.field_id')
            ->where('f.entity', $entity)->where('v.entity_id', $id)->pluck('v.value', 'f.key')->map(fn ($v): string => (string) $v)->all();
    }

    /**
     * Saves values by field key (an empty value clears it). Fields hidden from this person can't be changed by them.
     *
     * @param  array<string, mixed>  $values
     */
    public function save(string $entity, int $id, array $values, User $by, ?int $ownerUserId = null, bool $audit = true): void
    {
        $db = DB::connection(Connections::cell());
        $fields = $db->table('custom_fields')->where('entity', $entity)->whereNull('archived_at')->get()->keyBy('key');
        $changed = [];
        $errors = [];
        foreach ($values as $key => $value) {
            $f = $fields->get((string) $key);
            if ($f === null) {
                continue;
            }
            if ($f->hidden_from !== null && ! Fields::visible($by, (string) $f->hidden_from, $ownerUserId)) {
                $errors["fields.{$key}"] = "You can't change {$f->label}.";

                continue;
            }
            try {
                $stored = self::encode((string) $f->type, self::shape($f)['options'], $value);
            } catch (\InvalidArgumentException $e) {
                $errors["fields.{$key}"] = $f->label.': '.$e->getMessage();

                continue;
            }
            $current = $db->table('custom_field_values')->where('field_id', $f->id)->where('entity_id', $id)->value('value');
            if ($stored === null) {
                if ($current !== null) {
                    $db->table('custom_field_values')->where('field_id', $f->id)->where('entity_id', $id)->delete();
                    $changed[(string) $f->label] = ['old' => (string) $current, 'new' => null];
                }

                continue;
            }
            if ($current !== $stored) {
                $db->table('custom_field_values')->updateOrInsert(['field_id' => $f->id, 'entity_id' => $id],
                    ['tenant_id' => $by->tenant_id, 'value' => $stored, 'updated_by' => $by->id, 'updated_at' => CarbonImmutable::now()]);
                $changed[(string) $f->label] = ['old' => $current !== null ? (string) $current : null, 'new' => $stored];
            }
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
        if ($changed !== [] && $audit) {
            $this->audit->record('core.custom_field.values_changed', $by, changes: $changed, metadata: ['entity' => $entity, 'id' => $id], label: self::ENTITIES[$entity] ?? $entity);
        }
    }

    /**
     * Values from an import, by the column or field name in the other system. Unknown names become text fields, so
     * nothing brought over is lost (specs/core/10).
     *
     * @param  array<string, string>  $byLabel
     */
    public function import(string $entity, int $id, array $byLabel, User $by): void
    {
        $db = DB::connection(Connections::cell());
        $values = [];
        foreach (array_slice($byLabel, 0, 40, true) as $label => $value) {
            $label = mb_substr(trim((string) $label), 0, 80);
            $value = trim($value);
            if ($label === '' || $value === '') {
                continue;
            }
            $field = $db->table('custom_fields')->where('entity', $entity)->where(fn ($q) => $q->where('label', $label)->orWhere('key', Str::slug($label, '_')))->first();
            if ($field === null) {
                if ($db->table('custom_fields')->where('entity', $entity)->whereNull('archived_at')->count() >= self::MAX_PER_ENTITY) {
                    continue;
                }
                $key = mb_substr(Str::slug($label, '_') ?: 'field', 0, 60);
                $db->table('custom_fields')->insertOrIgnore(['ulid' => (string) Str::ulid(), 'tenant_id' => $by->tenant_id, 'entity' => $entity, 'key' => $key, 'label' => $label,
                    'type' => 'text', 'help' => 'Brought over by an import.', 'sort' => (int) $db->table('custom_fields')->where('entity', $entity)->max('sort') + 1,
                    'created_by' => $by->id, 'created_at' => CarbonImmutable::now(), 'updated_at' => CarbonImmutable::now()]);
                $field = $db->table('custom_fields')->where('entity', $entity)->where('key', $key)->first();
                $this->memo = [];
            }
            if ($field !== null && $field->archived_at === null) {
                $values[(string) $field->key] = $field->type === 'text' ? mb_substr($value, 0, 2000) : $value;
            }
        }
        try {
            $this->save($entity, $id, $values, $by, audit: false);
        } catch (ValidationException) {
            // A value that doesn't fit its field's type is left out rather than failing the import.
        }
    }

    /**
     * Labels of required deal fields with no value (they hold up closing, like a missing document).
     *
     * @return list<string>
     */
    public function missingToClose(int $dealId): array
    {
        $values = $this->raw('deal', $dealId);

        return array_values(array_map(fn (array $f): string => $f['label'], array_filter($this->definitions('deal'), fn (array $f): bool => $f['required_to_close'] && ($values[$f['key']] ?? '') === '')));
    }

    /**
     * Encodes a value for storage, or null to clear it.
     *
     * @param  list<string>  $options
     */
    public static function encode(string $type, array $options, mixed $value): ?string
    {
        if ($value === null || $value === '' || $value === []) {
            return null;
        }

        return match ($type) {
            'number' => is_numeric($value) ? (string) (0 + $value) : throw new \InvalidArgumentException('use a number.'),
            'money' => is_numeric(str_replace([',', '$'], '', (string) $value)) ? number_format((float) str_replace([',', '$'], '', (string) $value), 2, '.', '') : throw new \InvalidArgumentException('use an amount, like 1250.00.'),
            'date' => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $value) === 1 && strtotime((string) $value) !== false ? (string) $value : throw new \InvalidArgumentException('use a date (YYYY-MM-DD).'),
            'choice' => in_array((string) $value, $options, true) ? (string) $value : throw new \InvalidArgumentException('choose one of the choices.'),
            'multi' => ($picked = array_values(array_intersect($options, array_map('strval', (array) $value)))) === [] ? null : (string) json_encode($picked),
            'yesno' => in_array($value, [true, 1, '1', 'yes', 'true'], true) ? 'yes' : (in_array($value, [false, 0, '0', 'no', 'false'], true) ? 'no' : throw new \InvalidArgumentException('choose yes or no.')),
            'url' => filter_var((string) $value, FILTER_VALIDATE_URL) !== false && preg_match('#^https?://#i', (string) $value) === 1 ? mb_substr((string) $value, 0, 500) : throw new \InvalidArgumentException('use a full web address starting https://.'),
            default => mb_substr(trim((string) $value), 0, 2000) === '' ? null : mb_substr(trim((string) $value), 0, 2000),
        };
    }

    /**
     * @return string|list<string>|bool|null
     */
    public static function decode(string $type, ?string $stored): string|array|bool|null
    {
        if ($stored === null) {
            return null;
        }

        return match ($type) {
            'multi' => array_values(array_map('strval', (array) json_decode($stored, true))),
            'yesno' => $stored === 'yes',
            default => $stored,
        };
    }

    /**
     * @return array{ulid: string, key: string, label: string, type: string, options: list<string>, required_to_close: bool, hidden_from: string|null, help: string|null, archived: bool}
     */
    private static function shape(\stdClass $f): array
    {
        $options = $f->options !== null ? json_decode((string) $f->options, true) : [];

        return ['ulid' => (string) $f->ulid, 'key' => (string) $f->key, 'label' => (string) $f->label, 'type' => (string) $f->type,
            'options' => is_array($options) ? array_values(array_map('strval', $options)) : [], 'required_to_close' => (bool) $f->required_to_close,
            'hidden_from' => $f->hidden_from !== null ? (string) $f->hidden_from : null, 'help' => $f->help !== null ? (string) $f->help : null, 'archived' => $f->archived_at !== null];
    }

    private function guard(User $by): void
    {
        if (! self::manages($by)) {
            throw ValidationException::withMessages(['field' => 'Only owners, or a role with "Manage custom fields", can change fields.']);
        }
    }
}
