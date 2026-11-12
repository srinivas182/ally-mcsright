<?php

declare(strict_types=1);

/*
| The audit log's database user can only add and read events (specs/core/04, acceptance 2).
| Proven against real MySQL privileges. User management runs on a separate admin connection: MySQL
| commits implicitly on CREATE USER and GRANT. Needs DB_ADMIN_USERNAME (set in the MySQL CI job).
*/

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

uses(TestCase::class, DatabaseMigrations::class);

it('stops the audit database user from changing or deleting events', function (): void {
    $db = config('database.connections.mysql.database');
    config(['database.connections.mysql_admin' => array_merge(config('database.connections.mysql'), [
        'username' => env('DB_ADMIN_USERNAME'), 'password' => env('DB_ADMIN_PASSWORD', ''),
    ])]);
    $admin = DB::connection('mysql_admin');
    $admin->statement("CREATE USER IF NOT EXISTS 'audit_ro'@'%' IDENTIFIED BY 'audit-ro-pass-1'");
    $admin->statement("GRANT INSERT, SELECT ON `{$db}`.`audit_events` TO 'audit_ro'@'%'");
    config(['database.connections.audit_ro' => array_merge(config('database.connections.mysql'), ['username' => 'audit_ro', 'password' => 'audit-ro-pass-1'])]);

    $ro = DB::connection('audit_ro');
    try {
        expect($ro->table('audit_events')->count())->toBeInt()
            ->and(fn () => $ro->table('audit_events')->where('id', '>', 0)->update(['action' => 'x']))->toThrow(QueryException::class, 'denied')
            ->and(fn () => $ro->table('audit_events')->where('id', '>', 0)->delete())->toThrow(QueryException::class, 'denied');
    } finally {
        DB::purge('audit_ro');
        $admin->statement("DROP USER IF EXISTS 'audit_ro'@'%'");
        DB::purge('mysql_admin');
    }
})->skip(fn () => config('database.default') !== 'mysql' || env('DB_ADMIN_USERNAME') === null, 'MySQL with DB_ADMIN_USERNAME only');
