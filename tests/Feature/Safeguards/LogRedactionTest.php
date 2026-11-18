<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;
use Modules\Core\Privacy\Logging\RedactPersonalData;

/*
| SX1 (agent privacy): clients' personal details never reach log files or error reports.
*/

it('takes emails, phone numbers, SSNs and card numbers out of log lines and keeps ids, dates and prices', function (): void {
    $text = RedactPersonalData::text('Lead jane.doe+x@example.com (512) 555-0199 / 512-555-0100 / +15125550111 ssn 123-45-6789 card 4111 1111 1111 1111 '
        .'deal 01JZ8Q2N3M4K5P6R7S8T9V0W1X id 4821 on 2026-06-01 09:00 for $400,000');

    expect($text)->not->toContain('jane.doe')->not->toContain('555-0199')->not->toContain('555-0100')->not->toContain('5550111')
        ->not->toContain('123-45-6789')->not->toContain('4111')
        ->toContain('[email]')->toContain('[phone]')->toContain('[ssn]')->toContain('[number]')
        ->toContain('01JZ8Q2N3M4K5P6R7S8T9V0W1X')->toContain('id 4821')->toContain('2026-06-01 09:00')->toContain('$400,000');
});

it('blanks secret and personal context values, however deep, and writes exceptions without the client details they quote', function (): void {
    $sql = new QueryException('cell', "insert into crm_contacts (primary_email) values ('jane@x.test')", [], new RuntimeException('Duplicate jane@x.test'));
    $context = RedactPersonalData::context(['user' => ['email' => 'a@b.test', 'id' => 7, 'nested' => ['api_token' => 'abc']], 'password' => 'x',
        'note' => 'call 512.555.0123', 'exception' => $sql, 'contact_id' => 42]);

    expect($context['user']['email'])->toBe('[removed]')->and($context['user']['id'])->toBe(7)->and($context['user']['nested']['api_token'])->toBe('[removed]')
        ->and($context['password'])->toBe('[removed]')->and($context['note'])->toBe('call [phone]')->and($context['contact_id'])->toBe(42)
        ->and($context['exception'])->toBeString()->toContain(QueryException::class)->toContain('Caused by RuntimeException')->not->toContain('jane@x.test');
});

it('redacts what is actually written to the log file', function (): void {
    $path = storage_path('logs/sx1-redaction-'.getmypid().'.log');
    @unlink($path);
    config(['logging.channels.sx1' => array_merge(config('logging.channels.single'), ['path' => $path])]);

    Log::channel('sx1')->error('Could not text {who} at 512-555-0142', ['who' => 'jordan@x.test', 'exception' => new RuntimeException('Bounce for jordan@x.test')]);

    $written = (string) file_get_contents($path);
    @unlink($path);
    expect($written)->toContain('Could not text [email] at [phone]')->not->toContain('jordan@x.test')->not->toContain('555-0142');
});

it('attaches the redaction to every log channel', function (): void {
    $missing = collect(config('logging.channels'))->reject(fn (array $c, string $name): bool => ($c['driver'] ?? null) === 'stack' || $name === 'emergency')
        ->reject(fn (array $c): bool => in_array(RedactPersonalData::class, $c['tap'] ?? [], true))->keys()->all();

    expect($missing)->toBe([]);
});
