<?php

declare(strict_types=1);

use Modules\Core\Branding\ColorMath;
use Modules\Core\Branding\Oklch;

/*
| SX7: plain words and pre-test checks, run on every change (most RightAlly users are not technical, many are 50+).
| - Words people read on screen: no jargon (with the plain word to use instead).
| - Simple view and the help that goes with it: no small buttons or tiny text.
| - Colours: the design tokens' text and background pairs meet WCAG AA (4.5:1) in light and dark.
*/

/** Jargon → the plain words to use. Matched as whole words, any case, in text people read. */
const PW_JARGON = ['authentication' => 'sign-in', 'authenticating' => 'signing in', 'authenticate' => 'sign in', 'totp' => 'authenticator app', 'two-factor' => 'two-step',
    'credentials' => 'sign-in details', 'utilize' => 'use', 'utilise' => 'use', 'terminate' => 'end', 'commence' => 'start', 'initiate' => 'start', 'subsequently' => 'then',
    'functionality' => 'feature', 'leverage' => 'use', 'facilitate' => 'help', 'execute' => 'run', 'deprecated' => 'no longer used', 'configure' => 'set up',
    'invalid' => 'not right', 'parameter' => 'setting', 'parameters' => 'settings'];

/**
 * Text people read in a TSX file: JSX text between tags, and quoted strings that look like sentences (a space, a
 * capital or end punctuation), ignoring imports, comments, class names and addresses.
 *
 * @return list<string>
 */
function pwTexts(string $source): array
{
    $source = (string) preg_replace(['#/\*.*?\*/#s', '#^\s*//.*$#m', '#^import .*$#m', '#className=(["\'`])[^"\'`]*\1#', '#className=\{`[^`]*`\}#'], '', $source);
    $source = (string) preg_replace('/\$\{[^}]*\}/', ' ', $source); // template expressions are code, not words
    preg_match_all('/>([^<>{}]*[A-Za-z][^<>{}]*)</', $source, $jsx);
    preg_match_all('/(["\'`])((?:(?!\1).){3,}?)\1/', $source, $quoted);
    $prose = array_filter($quoted[2], fn (string $s): bool => preg_match('/[A-Za-z]{2,} [A-Za-z]{2,}/', $s) === 1 && preg_match('#^(/|https?:|[\w.-]+/|\w+:\w)#', $s) !== 1);

    return array_values(array_map('trim', [...$jsx[1], ...$prose]));
}

/** @return list<string> the TSX files people outside HQ see */
function pwFiles(): array
{
    $files = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__, 2).'/resources/js')) as $f) {
        $path = str_replace('\\', '/', (string) $f);
        if (str_ends_with($path, '.tsx') && ! str_contains($path, '/pages/hq/') && ! str_contains($path, '/pages/dev/') && ! str_contains($path, '/components/ui/')
            && ! str_ends_with($path, '.test.tsx')) {
            $files[] = $path;
        }
    }

    return $files;
}

it('uses plain words on every screen people outside HQ see', function (): void {
    $found = [];
    foreach (pwFiles() as $file) {
        foreach (pwTexts((string) file_get_contents($file)) as $text) {
            foreach (PW_JARGON as $word => $plain) {
                if (preg_match('/(?<![\w-])'.preg_quote($word, '/').'(?![\w-])/i', $text) === 1) {
                    $found[] = str_replace(dirname(__DIR__, 2).'/', '', $file).": \"{$word}\" → say \"{$plain}\" (in: ".mb_substr($text, 0, 60).')';
                }
            }
        }
    }

    expect($found)->toBe([]);
});

it('keeps buttons and text big in simple view and its help', function (): void {
    $files = [...glob(dirname(__DIR__, 2).'/resources/js/pages/simple/*.tsx') ?: [], dirname(__DIR__, 2).'/resources/js/components/help-me.tsx', dirname(__DIR__, 2).'/resources/js/components/second-factor-reminder.tsx'];
    $small = [];
    foreach ($files as $file) {
        $source = (string) file_get_contents($file);
        foreach (['size="sm"' => 'small button', 'text-xs' => 'tiny text', 'text-[10px]' => 'tiny text', 'text-[11px]' => 'tiny text', 'h-8 ' => 'short button'] as $needle => $what) {
            if (str_contains($source, $needle)) {
                $small[] = basename($file).": {$what} ({$needle})";
            }
        }
    }

    expect($small)->toBe([]);
});

it('meets WCAG AA contrast for the design tokens\' text on its background, in light and dark', function (): void {
    $css = (string) file_get_contents(dirname(__DIR__, 2).'/resources/css/tokens.css');
    $low = [];
    foreach (['light' => '/:root\s*\{(.*?)\}/s', 'dark' => '/\.dark\s*\{(.*?)\}/s'] as $theme => $block) {
        if (preg_match_all($block, $css, $blocks) < 1) {
            continue;
        }
        $vars = [];
        foreach ($blocks[1] as $b) {
            preg_match_all('/--([\w-]+):\s*oklch\(([\d.]+)\s+([\d.]+)\s+([\d.]+)\)/', $b, $m, PREG_SET_ORDER);
            foreach ($m as $v) {
                $vars[$v[1]] = new Oklch((float) $v[2], (float) $v[3], (float) $v[4]);
            }
        }
        foreach ([['foreground', 'background'], ['muted-foreground', 'background'], ['muted-foreground', 'muted'], ['primary-foreground', 'primary'], ['card-foreground', 'card'],
            ['secondary-foreground', 'secondary'], ['accent-foreground', 'accent'], ['sidebar-foreground', 'sidebar'], ['primary', 'background']] as [$fg, $bg]) {
            if (! isset($vars[$fg], $vars[$bg])) {
                continue;
            }
            $ratio = ColorMath::contrast($vars[$fg], $vars[$bg]);
            if ($ratio < 4.5) {
                $low[] = "{$theme}: {$fg} on {$bg} is ".round($ratio, 2).':1 (needs 4.5:1)';
            }
        }
    }

    expect($low)->toBe([]);
});
