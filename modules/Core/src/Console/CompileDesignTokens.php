<?php

declare(strict_types=1);

namespace Modules\Core\Console;

use Illuminate\Console\Command;
use Modules\Core\Branding\DesignTokens;

/**
 * php artisan design:tokens          write resources/css/tokens.css
 * php artisan design:tokens --check  fail if the committed file is out of date (CI)
 */
final class CompileDesignTokens extends Command
{
    protected $signature = 'design:tokens {--check : Fail when tokens.css is out of date instead of writing it}';

    protected $description = 'Compile design tokens into CSS custom properties';

    public function handle(DesignTokens $tokens): int
    {
        $source = resource_path('design/tokens.json');
        $target = resource_path('css/tokens.css');
        $css = $tokens->compile($source);

        if ($this->option('check')) {
            $current = is_file($target) ? (string) file_get_contents($target) : '';
            if ($current !== $css) {
                $this->error('resources/css/tokens.css is out of date. Run: php artisan design:tokens');

                return self::FAILURE;
            }
            $this->info('Design tokens are up to date.');

            return self::SUCCESS;
        }

        file_put_contents($target, $css);
        $this->info('Wrote resources/css/tokens.css');

        return self::SUCCESS;
    }
}
