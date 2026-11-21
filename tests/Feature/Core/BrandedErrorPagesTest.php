<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

/* BR2: RightAlly's own error pages, in plain words, never showing technical detail. */

it('shows RightAlly error pages with plain words and no technical detail', function (int $code, string $title): void {
    config(['app.debug' => false]);
    Route::get('/__br2/{code}', fn (int $code) => abort($code));

    $this->get("/__br2/{$code}")->assertStatus($code)->assertSee($title)->assertSee('aria-label="RightAlly"', false)
        ->assertDontSee('Symfony')->assertDontSee('vendor/');
})->with([[403, 'This page is not for your account'], [404, 'We could not find that page'], [419, 'Your page timed out'],
    [429, 'Too many tries in a short time'], [500, 'Something went wrong on our side'], [503, 'RightAlly is being updated'], [418, 'That did not work']]);
