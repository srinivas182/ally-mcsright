<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;

/*
| MySQL rejects identifiers longer than 64 characters; SQLite does not. Laravel's generated index
| names grow with the column list, so this guard runs on SQLite in every CI run (found in Sprint 3B).
*/

it('keeps every table, column and index name within MySQL\'s 64-character limit', function (): void {
    $tooLong = [];

    foreach (Schema::getTableListing(schemaQualified: false) as $table) {
        if (strlen($table) > 64) {
            $tooLong[] = "table {$table}";
        }

        foreach (Schema::getColumnListing($table) as $column) {
            if (strlen($column) > 64) {
                $tooLong[] = "column {$table}.{$column}";
            }
        }

        foreach (Schema::getIndexes($table) as $index) {
            if (strlen((string) $index['name']) > 64) {
                $tooLong[] = "index {$index['name']}";
            }
        }
    }

    expect($tooLong)->toBe([]);
});
