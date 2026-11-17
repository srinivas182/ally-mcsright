<?php

declare(strict_types=1);

use App\Models\User;
use Carbon\CarbonImmutable;
use Modules\Sites\SiteSetup;

/*
| AX2 (specs/core/13): the public pages buyers and sellers see pass the basic WCAG 2.2 AA markup checks: a
| language, one main heading, a skip link to the main content, alt text on every image, a name for every form
| field, button and link, and no positive tab order.
*/

/** @return list<string> problems found */
function axAudit(string $html): array
{
    $doc = new DOMDocument;
    libxml_use_internal_errors(true);
    $doc->loadHTML($html);
    libxml_clear_errors();
    $x = new DOMXPath($doc);
    $problems = [];
    $html = $doc->documentElement;
    if (! $html instanceof DOMElement || trim($html->getAttribute('lang')) === '') {
        $problems[] = 'no lang on <html>';
    }
    if ($x->query('//h1')->length !== 1) {
        $problems[] = $x->query('//h1')->length.' h1 headings';
    }
    $skip = $x->query('//a[starts-with(@href, "#")]')->item(0);
    $target = $skip instanceof DOMElement ? substr($skip->getAttribute('href'), 1) : '';
    if ($target === '' || $x->query('//*[@id="'.$target.'"]')->length === 0) {
        $problems[] = 'no working skip link';
    }
    foreach ($x->query('//img[not(@alt)]') as $img) {
        $problems[] = 'image without alt: '.($img instanceof DOMElement ? $img->getAttribute('src') : '');
    }
    $named = function (DOMElement $el) use ($x): bool {
        if (trim($el->getAttribute('aria-label')) !== '' || trim($el->getAttribute('aria-labelledby')) !== '' || trim($el->getAttribute('title')) !== '') {
            return true;
        }
        $id = $el->getAttribute('id');
        if ($id !== '' && $x->query('//label[@for="'.$id.'"]')->length > 0) {
            return true;
        }
        for ($p = $el->parentNode; $p instanceof DOMElement; $p = $p->parentNode) {
            if ($p->tagName === 'label') {
                return true;
            }
        }

        return false;
    };
    foreach ($x->query('//input[not(@type="hidden") and not(@type="submit") and not(@type="button")] | //select | //textarea') as $field) {
        if ($field instanceof DOMElement && ! $named($field)) {
            $problems[] = 'form field without a label: '.$field->getAttribute('name');
        }
    }
    foreach ($x->query('//button | //a[@href]') as $el) {
        if ($el instanceof DOMElement && trim($el->textContent) === '' && trim($el->getAttribute('aria-label')) === '' && trim($el->getAttribute('title')) === ''
            && $x->query('.//img[@alt!=""]', $el)->length === 0) {
            $problems[] = $el->tagName.' without a name: '.$el->getAttribute('href');
        }
    }
    foreach ($x->query('//*[@tabindex]') as $el) {
        if ($el instanceof DOMElement && (int) $el->getAttribute('tabindex') > 0) {
            $problems[] = 'positive tabindex on '.$el->tagName;
        }
    }

    return array_values(array_unique($problems));
}

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-06-01 15:00', 'UTC'));
    $this->tenant = $this->onTenantHost('acme');
    $this->owner = User::factory()->owner()->create();
    $this->web = 'http://acme.rightally.site';
    $sites = app(SiteSetup::class);
    $sites->publish($sites->create('modern', ['name' => 'Acme Realty', 'phone' => '512-555-0100', 'email' => 'hi@acme.test'], $this->owner), true, $this->owner);
});

it('passes the basic accessibility checks on the public website pages', function (string $path): void {
    $response = $this->get($this->web.$path)->assertOk();

    expect(axAudit((string) $response->getContent()))->toBe([]);
})->with(['/', '/homes', '/home-value', '/privacy', '/accessibility']);
