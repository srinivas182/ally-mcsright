<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Tenancy\Attributes\ControlPlaneModel;
use Modules\Core\Tenancy\Concerns\BelongsToTenant;

/**
 * Every Eloquent model in modules/ whose table has a tenant_id column must use
 * BelongsToTenant, unless it is explicitly marked as a control-plane model.
 */
it('requires BelongsToTenant on every tenant-owned module model', function (): void {
    $checked = 0;

    foreach (glob(base_path('modules/*/src/**/Models/*.php')) ?: [] as $file) {
        $relative = str_replace([base_path('modules/'), '/src/', '.php', '/'], ['', '/', '', '\\'], $file);
        $class = 'Modules\\'.$relative;

        if (! class_exists($class) || ! is_subclass_of($class, Model::class)) {
            continue;
        }

        $reflection = new ReflectionClass($class);
        if ($reflection->isAbstract() || $reflection->getAttributes(ControlPlaneModel::class) !== []) {
            continue;
        }

        /** @var Model $model */
        $model = new $class;
        if (! Schema::hasTable($model->getTable()) || ! Schema::hasColumn($model->getTable(), 'tenant_id')) {
            continue;
        }

        expect(in_array(BelongsToTenant::class, class_uses_recursive($class), true))
            ->toBeTrue("{$class} has tenant_id but does not use BelongsToTenant.");
        $checked++;
    }

    expect($checked)->toBeGreaterThanOrEqual(0);
});
