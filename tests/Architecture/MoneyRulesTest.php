<?php

declare(strict_types=1);

/*
| Money is never a float (specs/finance/01). Any float-typed property, parameter or
| return type in money modules fails the build.
*/

it('uses no float types in money modules', function (): void {
    $offenders = [];

    foreach (['Ledger'] as $module) {
        $base = dirname(__DIR__, 2)."/modules/{$module}/src";
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base));

        foreach ($files as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $class = 'Modules\\'.$module.'\\'.str_replace(['/', '.php'], ['\\', ''], substr($file->getPathname(), strlen($base) + 1));
            if (! class_exists($class) && ! enum_exists($class)) {
                continue;
            }

            $reflection = new ReflectionClass($class);
            $isFloat = fn (?ReflectionType $type): bool => $type !== null && str_contains((string) $type, 'float');

            foreach ($reflection->getProperties() as $property) {
                if ($isFloat($property->getType())) {
                    $offenders[] = "{$class}::\${$property->getName()}";
                }
            }

            foreach ($reflection->getMethods() as $method) {
                if ($isFloat($method->getReturnType())) {
                    $offenders[] = "{$class}::{$method->getName()}() return";
                }
                foreach ($method->getParameters() as $parameter) {
                    if ($isFloat($parameter->getType())) {
                        $offenders[] = "{$class}::{$method->getName()}(\${$parameter->getName()})";
                    }
                }
            }
        }
    }

    expect($offenders)->toBe([]);
});
