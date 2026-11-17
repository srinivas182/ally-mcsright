<?php

declare(strict_types=1);

namespace Modules\Sites\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Modules\Core\Tenancy\TenantContext;
use Modules\Sites\Models\Site;
use Modules\Sites\Rendering\Chrome;
use Modules\Sites\Rendering\Seo;
use Modules\Sites\Tools\HomeCalculators;

/**
 * /calculators on the website (AW1, specs/websites/18). Plain HTML forms that submit with GET, so they work without
 * JavaScript, every answer is a shareable link, and search engines index the clean pages (with FAQ and HowTo data).
 */
final class CalculatorController
{
    public function index(Request $request): Response
    {
        $site = Site::query()->where('kind', 'brokerage')->where('status', 'live')->first();
        if ($site === null) {
            return self::missing();
        }
        $base = $request->getSchemeAndHttpHost();

        return response()->view('sites::calculators.index', [
            'site' => $site, 'current' => '/calculators', 'kinds' => HomeCalculators::KINDS,
            'seo' => Seo::build($site, $base, '/calculators', 'Home buying calculators | '.$site->displayName(),
                'Free calculators for a monthly mortgage payment, how much house you can afford, and whether to rent or buy.', null, true, [['Calculators', '/calculators']]),
            'chrome' => Chrome::for($site, app(TenantContext::class)->id()),
        ])->header('Cache-Control', 'public, max-age=300, s-maxage=900');
    }

    public function show(Request $request, string $kind): Response
    {
        $site = Site::query()->where('kind', 'brokerage')->where('status', 'live')->first();
        if ($site === null || ! isset(HomeCalculators::KINDS[$kind])) {
            return self::missing($site);
        }
        $d = HomeCalculators::defaults($site);
        $asked = $request->query() !== [];
        $money = static fn (string $k, int $fallback, int $max): int => $request->filled($k) ? max(0, min($max, (int) preg_replace('/[^\d]/', '', (string) $request->query($k)))) : $fallback;
        $pct = static fn (string $k, float $fallback, float $max): float => $request->filled($k) && is_numeric($request->query($k)) ? max(0.0, min($max, (float) $request->query($k))) : $fallback;
        $years = in_array((int) $request->query('years'), [10, 15, 20, 30], true) ? (int) $request->query('years') : $d['years'];

        $in = match ($kind) {
            'mortgage' => ['price' => $money('price', 400_000, 50_000_000), 'down_pct' => $pct('down_pct', $d['down_pct'], 100), 'rate' => $pct('rate', $d['rate'], 20), 'years' => $years,
                'tax_rate' => $pct('tax_rate', $d['tax_rate'], 5), 'insurance' => $money('insurance', $d['insurance'], 5_000), 'hoa' => $money('hoa', 0, 5_000)],
            'affordability' => ['income' => $money('income', 100_000, 10_000_000), 'debts' => $money('debts', 500, 100_000), 'down' => $money('down', 40_000, 10_000_000),
                'rate' => $pct('rate', $d['rate'], 20), 'years' => $years, 'tax_rate' => $pct('tax_rate', $d['tax_rate'], 5), 'insurance' => $money('insurance', $d['insurance'], 5_000),
                'hoa' => $money('hoa', 0, 5_000)],
            default => ['rent' => $money('rent', 2_000, 100_000), 'price' => $money('price', 400_000, 50_000_000), 'down_pct' => $pct('down_pct', $d['down_pct'], 100),
                'rate' => $pct('rate', $d['rate'], 20), 'years' => $years, 'stay' => max(1, min(30, (int) ($request->query('stay') ?: 7))), 'tax_rate' => $pct('tax_rate', $d['tax_rate'], 5),
                'insurance' => $money('insurance', $d['insurance'], 5_000), 'appreciation' => $pct('appreciation', $d['appreciation'], 15), 'rent_growth' => $pct('rent_growth', $d['rent_growth'], 15)],
        };
        $result = match ($kind) {
            'mortgage' => HomeCalculators::mortgage($in['price'], $in['down_pct'], $in['rate'], $in['years'], $in['tax_rate'], $in['insurance'], $in['hoa'], $d['pmi_rate']),
            'affordability' => HomeCalculators::affordability($in['income'], $in['debts'], $in['down'], $in['rate'], $in['years'], $in['tax_rate'], $in['insurance'], $in['hoa']),
            default => HomeCalculators::rentVsBuy($in['rent'], $in['price'], $in['down_pct'], $in['rate'], $in['years'], $in['stay'], $in['tax_rate'], $in['insurance'],
                $in['appreciation'], $in['rent_growth']),
        };
        $meta = HomeCalculators::KINDS[$kind];
        $faqs = self::faqs($kind, $d);
        $base = $request->getSchemeAndHttpHost();
        $steps = self::steps($kind);
        $howTo = ['@type' => 'HowTo', 'name' => $meta['title'], 'step' => array_map(fn (string $s, int $i): array => ['@type' => 'HowToStep', 'position' => $i + 1, 'text' => $s],
            $steps, array_keys($steps))];

        return response()->view('sites::calculators.show', [
            'site' => $site, 'current' => '/calculators', 'kind' => $kind, 'meta' => $meta, 'in' => $in, 'result' => $result, 'faqs' => $faqs, 'others' => array_diff_key(HomeCalculators::KINDS, [$kind => 1]),
            'seo' => Seo::build($site, $base, '/calculators/'.$kind, $meta['title'].' | '.$site->displayName(), $meta['description'], null, ! $asked,
                [['Calculators', '/calculators'], [$meta['short'], '/calculators/'.$kind]], $faqs, [$howTo]),
            'chrome' => Chrome::for($site, app(TenantContext::class)->id()),
        ])->header('Cache-Control', $asked ? 'no-store' : 'public, max-age=300, s-maxage=900');
    }

    /**
     * @param  array{rate: float, down_pct: float, years: int, tax_rate: float, insurance: int, pmi_rate: float, appreciation: float, rent_growth: float}  $d
     * @return list<array{q: string, a: string}>
     */
    private static function faqs(string $kind, array $d): array
    {
        return match ($kind) {
            'mortgage' => [
                ['q' => 'What is included in a monthly mortgage payment?', 'a' => 'Principal and interest on the loan, plus property tax and home insurance (often paid through the lender), HOA dues if the home has them, and mortgage insurance when the down payment is under 20%.'],
                ['q' => 'What interest rate does this calculator start with?', 'a' => 'It starts at '.$d['rate'].'%, a typical rate we update from time to time. Your own rate depends on your credit, loan type and down payment; change it to match a quote.'],
                ['q' => 'Do I need 20% down?', 'a' => 'No. Many loans allow 3% to 5% down, and some none at all. Under 20% usually adds mortgage insurance until you owe less than 80% of the home value.'],
            ],
            'affordability' => [
                ['q' => 'How much house can I afford on my income?', 'a' => 'A common starting point is the 28/36 guideline: housing costs up to 28% of your gross monthly income, and all debts including housing up to 36%. Enter your numbers above to see the price that fits.'],
                ['q' => 'Is the result a pre-approval?', 'a' => 'No. It is an estimate. A lender looks at your credit, savings and the loan type to give you a pre-approval, which sellers expect with an offer.'],
            ],
            default => [
                ['q' => 'Is it better to rent or buy?', 'a' => 'It depends mostly on how long you stay. Buying has upfront and selling costs, so it usually wins only after several years, as the home gains value and you pay down the loan. Enter your numbers above to see your break-even year.'],
                ['q' => 'What does this rent or buy calculator leave out?', 'a' => 'Tax deductions and what your down payment could earn if invested instead. It includes closing costs, upkeep and selling costs.'],
            ],
        };
    }

    /**
     * @return list<string>
     */
    private static function steps(string $kind): array
    {
        return match ($kind) {
            'mortgage' => ['Enter the home price and your down payment.', 'Enter the interest rate and loan term.', 'Add property tax, insurance and any HOA dues.', 'Read your monthly payment and what makes it up.'],
            'affordability' => ['Enter your yearly income before tax.', 'Enter your monthly debt payments, such as car loans and cards.', 'Enter your down payment savings and the interest rate.', 'Read the home price that fits your budget.'],
            default => ['Enter your monthly rent.', 'Enter the price of the home you would buy, your down payment and rate.', 'Enter how many years you plan to stay.', 'Compare the total cost of renting and buying.'],
        };
    }

    private static function missing(?Site $site = null): Response
    {
        return response()->view('sites::missing', ['site' => $site], 404)->header('Cache-Control', 'public, max-age=60');
    }
}
