<?php

declare(strict_types=1);

namespace Modules\Pricing\Http;

use App\Models\User;
use Illuminate\Http\Response;
use Modules\Ads\Studio\BrandKits;
use Modules\Core\Tenancy\TenantContext;
use Modules\Listings\Media\Photos;
use Modules\Listings\Models\Listing;
use Modules\Pricing\Cmas;
use Modules\Pricing\Models\Cma;
use Modules\Pricing\Models\Sheet;
use Modules\Pricing\Sheets;

/**
 * The seller's CMA and the buyer's estimate behind their private links (CM1): branded to the agent, no sign-in,
 * never indexed, no referrer. Comp photos only where the MLS allows sold photos to be shown.
 */
final class ShareController
{
    public function cma(string $token, Cmas $cmas): Response
    {
        $cma = $cmas->byToken($token);
        if ($cma === null) {
            return self::private(response()->view('pricing::gone', ['tenant' => app(TenantContext::class)->current()->name], 404));
        }
        $cmas->viewed($cma);

        return self::private(response()->view('pricing::cma', self::cmaView($cma, false)));
    }

    public function estimate(string $token, Sheets $sheets): Response
    {
        $sheet = $sheets->byToken($token);
        if ($sheet === null) {
            return self::private(response()->view('pricing::gone', ['tenant' => app(TenantContext::class)->current()->name], 404));
        }
        $sheets->viewed($sheet);

        return self::private(response()->view('pricing::estimate', self::sheetView($sheet, false)));
    }

    /** @return array<string, mixed> */
    public static function cmaView(Cma $cma, bool $pdf): array
    {
        $r = (array) $cma->result;
        $ids = array_column((array) ($r['comps'] ?? []), 'listing_id');
        $photosOk = Listing::query()->whereIn('id', $ids ?: [0])->with('source.rulesProfile')->get()
            ->mapWithKeys(fn (Listing $l): array => [$l->id => ! (bool) ($r['sold'] ?? false) || ($l->source->rulesProfile !== null && $l->source->rulesProfile->sold_allowed)]);
        $r['comps'] = array_map(fn (array $c): array => [...$c, 'photo' => ($photosOk[$c['listing_id']] ?? false) ? Photos::url($c['photo'] ?? null) : null], (array) ($r['comps'] ?? []));

        return ['cma' => $cma, 'r' => $r, 'brand' => self::brand($cma->owner_user_id), 'pdf' => $pdf, 'es' => $cma->lang === 'es',
            'summary' => $cma->summary ?? Cmas::plainSummary($cma)];
    }

    /** @return array<string, mixed> */
    public static function sheetView(Sheet $sheet, bool $pdf): array
    {
        return ['sheet' => $sheet, 'r' => $sheet->result, 'i' => $sheet->inputs, 'brand' => self::brand($sheet->owner_user_id), 'pdf' => $pdf, 'es' => $sheet->lang === 'es'];
    }

    /** @return array<string, mixed> */
    private static function brand(int $userId): array
    {
        $user = User::query()->find($userId);

        return $user !== null ? app(BrandKits::class)->forUser($user) : ['colors' => BrandKits::DEFAULT_COLORS, 'fonts' => ['heading' => 'Georgia', 'body' => 'Arial'], 'details' => [],
            'logo' => null, 'headshot' => null, 'brokerage' => app(TenantContext::class)->current()->name];
    }

    private static function private(Response $r): Response
    {
        return $r->withHeaders(['X-Robots-Tag' => 'noindex, nofollow', 'Cache-Control' => 'private, no-store', 'Referrer-Policy' => 'no-referrer']);
    }
}
