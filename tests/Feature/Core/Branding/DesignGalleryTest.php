<?php

declare(strict_types=1);

it('does not expose the component workbench outside local or opted-in staging', function (): void {
    expect(app()->environment('local'))->toBeFalse()
        ->and(config('core.design_gallery'))->toBeFalse();

    $this->get('http://localhost/dev/design')->assertNotFound();
});
