<?php

declare(strict_types=1);

use Modules\Core\Tenancy\Host;
use Modules\Core\Tenancy\Slug;
use Tests\TestCase;

uses(TestCase::class);

it('accepts valid slugs', function (string $slug): void {
    expect(Slug::isValid($slug))->toBeTrue();
})->with(['jones-homes', 'abc', 'lake-area-realty-2026', str_repeat('a', 40)]);

it('rejects invalid slugs', function (string $slug): void {
    expect(Slug::problem($slug))->not->toBeNull();
})->with([
    'too short' => 'ab',
    'too long' => str_repeat('a', 41),
    'upper case' => 'Jones',
    'leading hyphen' => '-jones',
    'trailing hyphen' => 'jones-',
    'double hyphen' => 'jones--homes',
    'underscore' => 'jones_homes',
    'reserved' => 'admin',
    'space' => 'jones homes',
]);

it('normalises hosts', function (): void {
    expect(Host::normalise(' WWW.Example.COM.:443 '))->toBe('www.example.com')
        ->and(Host::platformHostFor('jones'))->toBe('jones.rightally.app');
});
