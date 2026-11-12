<?php

declare(strict_types=1);

namespace Modules\Core\Audit;

/**
 * Records create, update and delete of a model with field-level changes. The model declares:
 *   protected static string $auditAction = 'crm.contact';
 *   protected static array $audited = ['first_name', ...];        // fields worth recording
 *   protected static array $auditMasked = ['primary_phone', ...]; // shown as ***1234
 */
trait Auditable
{
    public static function bootAuditable(): void
    {
        static::created(fn (self $model) => $model->writeAudit('created', array_keys($model->getAttributes())));
        static::updated(fn (self $model) => $model->writeAudit('updated', array_keys($model->getChanges())));
        static::deleted(fn (self $model) => $model->writeAudit('deleted', []));
    }

    /**
     * @param  list<string>  $fields
     */
    private function writeAudit(string $verb, array $fields): void
    {
        $changes = [];
        foreach (array_intersect($fields, static::$audited) as $field) {
            $old = $verb === 'created' ? null : $this->getOriginal($field);
            $new = $this->getAttribute($field);
            $old = $old instanceof \BackedEnum ? $old->value : ($old instanceof \DateTimeInterface ? $old->format('Y-m-d H:i:s') : $old);
            $new = $new instanceof \BackedEnum ? $new->value : ($new instanceof \DateTimeInterface ? $new->format('Y-m-d H:i:s') : $new);
            if ($verb === 'updated' && $old == $new) {
                continue;
            }
            $masked = in_array($field, static::$auditMasked, true);
            $changes[$field] = ['old' => $masked ? AuditLogger::mask($old) : $old, 'new' => $masked ? AuditLogger::mask($new) : $new];
        }

        if ($verb === 'updated' && $changes === []) {
            return;
        }

        app(AuditLogger::class)->record(static::$auditAction.'.'.$verb, $this, $changes, severity: $verb === 'deleted' ? 'notice' : 'info');
    }
}
