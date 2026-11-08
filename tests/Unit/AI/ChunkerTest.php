<?php

declare(strict_types=1);

use Modules\AI\Retrieval\Chunker;

it('keeps short text as one chunk', function (): void {
    expect((new Chunker)->split('  Short note.  '))->toBe(['Short note.'])
        ->and((new Chunker)->split('   '))->toBe([]);
});

it('splits long text into overlapping chunks within the size limit on word boundaries', function (): void {
    $sentence = 'The buyer wants three bedrooms near the river and good schools. ';
    $text = str_repeat($sentence, 60);
    $chunks = (new Chunker(500, 100))->split($text);

    expect(count($chunks))->toBeGreaterThan(5);
    foreach ($chunks as $chunk) {
        expect(mb_strlen($chunk))->toBeLessThanOrEqual(500)
            ->and($chunk)->toStartWith('The buyer');
    }
    // Every sentence position is covered: the last chunk ends where the text ends.
    expect(end($chunks))->toEndWith('good schools.');
});
