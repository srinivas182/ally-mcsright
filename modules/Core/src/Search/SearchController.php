<?php

declare(strict_types=1);

namespace Modules\Core\Search;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Modules\Core\Simple\SimpleMode;
use Modules\Core\Switches\FeatureSwitches;
use Throwable;

/**
 * GET /search?q=… (PW1, specs/core/17): contacts, deals, listings and people the person may see, grouped, a few of each.
 * Results whose page the person cannot open (an area switched off, no seat) are left out. One provider failing never
 * breaks the others.
 */
final class SearchController
{
    public const PER_GROUP = 5;

    public function __invoke(Request $request, SearchRegistry $registry, FeatureSwitches $switches): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);
        $q = trim(mb_substr((string) $request->query('q', ''), 0, 80));
        if (mb_strlen($q) < 2) {
            return response()->json(['groups' => []]);
        }
        $locale = SimpleMode::locale($user);
        $groups = [];
        foreach ($registry->all() as $key => $provider) {
            try {
                $items = array_values(array_filter(($provider['find'])($user, $q, self::PER_GROUP), fn (array $i): bool => $switches->allowsPath($user, $i['href'])));
            } catch (Throwable $e) {
                Log::warning('Search provider failed', ['provider' => $key, 'error' => $e->getMessage()]);

                continue;
            }
            if ($items !== []) {
                $groups[] = ['key' => $key, 'label' => $provider['label'][$locale] ?? $provider['label']['en'], 'items' => array_slice($items, 0, self::PER_GROUP)];
            }
        }

        return response()->json(['groups' => $groups]);
    }

    /** A LIKE pattern for user text, with % and _ taken literally. */
    public static function like(string $q): string
    {
        return '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q).'%';
    }
}
