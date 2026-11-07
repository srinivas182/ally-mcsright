<?php

declare(strict_types=1);

use Modules\AI\Security\OutputPolicy;
use Modules\AI\Security\Redactor;
use Modules\AI\Support\JsonShape;

it('redacts SSNs, Luhn-valid cards, labelled bank numbers and dates of birth', function (): void {
    $redactor = new Redactor;
    $out = $redactor->redact('SSN 123-45-6789 / SSN: 987654320 / card 4111-1111-1111-1111 / account no. 12345678 / DOB 1/2/1980');

    expect($out)->toBe('SSN [REDACTED_SSN_1] / SSN: [REDACTED_SSN_2] / card [REDACTED_CARD_1] / account no. [REDACTED_BANK_1] / DOB [REDACTED_DOB_1]')
        ->and($redactor->map()['[REDACTED_SSN_1]'])->toBe('123-45-6789');
});

it('leaves ordinary numbers alone', function (): void {
    $text = 'List price 450000, MLS# 1234567890123, call 601-555-0100, 3 beds 2 baths';

    expect((new Redactor)->redact($text))->toBe($text);
});

it('reuses the same placeholder for repeated values', function (): void {
    expect((new Redactor)->redact('123-45-6789 and again 123-45-6789'))->toBe('[REDACTED_SSN_1] and again [REDACTED_SSN_1]');
});

it('reports only the kinds of unpermitted identifiers', function (): void {
    $policy = new OutputPolicy;

    expect($policy->violations('Email bob@x.com or call 601-555-0100', 'Bob bob@x.com 6015550100'))->toBe([])
        ->and($policy->violations('Try eve@y.com', 'Bob bob@x.com'))->toBe(['email'])
        ->and($policy->violations('Record 01JC9Z3N5Q7R8S9T0V1W2X3Y4Z', 'nothing'))->toBe(['record_id']);
});

it('validates JSON shapes', function (): void {
    $shape = new JsonShape;
    $schema = ['type' => 'object', 'required' => ['tier'], 'properties' => ['tier' => ['type' => 'string', 'enum' => ['hot', 'warm', 'cold']], 'tags' => ['type' => 'array', 'items' => ['type' => 'string']]]];

    expect($shape->validate(['tier' => 'hot', 'tags' => ['buyer']], $schema))->toBe([])
        ->and($shape->validate(['tier' => 'lukewarm'], $schema))->toBe(['$.tier must be one of hot, warm, cold'])
        ->and($shape->validate(['tags' => [1]], $schema))->toBe(['$.tier is required', '$.tags[0] must be string'])
        ->and($shape->validate([1, 2], $schema))->toBe(['$ must be object']);
});
