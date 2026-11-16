<?php

declare(strict_types=1);

namespace Modules\Movewise\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Modules\Movewise\I18n;
use Modules\Movewise\Tools\Affordability;
use Modules\Movewise\Tools\MovePlanner;
use Modules\Movewise\Tools\NetSheet;

/**
 * Movewise's calculators (specs/movewise/02): affordability, the seller net sheet and the move planner. They work
 * without JavaScript (the form sends the numbers and the answer is drawn on the server), explain every step, and are
 * pages search engines and AI answers can read.
 */
final class ToolsController
{
    public function __construct(private readonly SiteController $site) {}

    public function afford(Request $request): Response
    {
        $chrome = $this->site->chrome();
        $state = $this->state($request, $chrome);
        $in = ['income' => $this->num($request, 'income'), 'debts' => $this->num($request, 'debts'), 'down' => $this->num($request, 'down'), 'state' => $state];
        $result = $in['income'] > 0 ? Affordability::for($in['income'], $in['debts'], $in['down'], $state) : null;

        return $this->page('movewise::tools.afford', $chrome, '/afford', I18n::t('tools.afford'), 'Work out what you can comfortably afford, step by step, with the true monthly cost.',
            ['in' => $in, 'result' => $result], $request->query() === [], [['How much house can I afford?', 'Lenders usually keep your home payment (loan, tax and insurance) under 28% of your income before tax, and all your debts under 36%. The smaller of the two is a comfortable payment; the price that fits it depends on your down payment and rates.']]);
    }

    public function netSheet(Request $request): Response
    {
        $chrome = $this->site->chrome();
        $state = $this->state($request, $chrome);
        $in = ['price' => $this->num($request, 'price'), 'payoff' => $this->num($request, 'payoff'), 'listing_pct' => $this->pct($request, 'listing_pct'),
            'buyer_pct' => $this->pct($request, 'buyer_pct'), 'repairs' => $this->num($request, 'repairs'), 'state' => $state];
        $result = $in['price'] > 0 ? NetSheet::for($in['price'], $in['payoff'], $in['listing_pct'], $in['buyer_pct'], $in['repairs'], $state) : null;

        return $this->page('movewise::tools.net-sheet', $chrome, '/net-sheet', I18n::t('tools.net'), 'See what you would walk away with when you sell: payoff, agents, closing costs and repairs.',
            ['in' => $in, 'result' => $result], $request->query() === [], [['How much will I get when I sell my house?', 'Start from the sale price, then take off your mortgage payoff, the commission you agree with your listing agent, any amount you choose to offer the buyer\'s agent, closing costs (title, escrow and recording, about 1% to 1.5%), and repairs or credits.'],
                ['Do I have to pay the buyer\'s agent?', 'No. Commissions are negotiable. Whether you offer to pay the buyer\'s agent is your choice, often agreed in the offer.']]);
    }

    public function planner(Request $request): Response
    {
        $chrome = $this->site->chrome();
        $date = (string) $request->query('move_date', '');

        return $this->page('movewise::tools.planner', $chrome, '/move-planner', I18n::t('tools.planner'), 'A moving checklist dated back from your moving day: movers, utilities, address changes and more.',
            ['plan' => MovePlanner::plan($date !== '' ? $date : null, []), 'move_date' => $date], $request->query() === [], []);
    }

    /**
     * @param  array{base: string, states: list<array{code: string}>, year: int}  $chrome
     * @param  array<string, mixed>  $data
     * @param  list<array{0: string, 1: string}>  $faqs
     */
    private function page(string $view, array $chrome, string $path, string $title, string $description, array $data, bool $indexable, array $faqs): Response
    {
        return response()->view($view, $data + ['site' => $chrome, 'title' => $title,
            'seo' => $this->site->seo($chrome, $path, $title.' | Movewise Homes', $description, $indexable, [], $faqs, [[$title, $path]])])
            ->header('Cache-Control', 'public, max-age=60, s-maxage=300');
    }

    /** @param  array{states: list<array{code: string}>}  $chrome */
    private function state(Request $request, array $chrome): string
    {
        $s = strtoupper((string) $request->query('state', ''));
        $on = array_column($chrome['states'], 'code');

        return in_array($s, $on, true) ? $s : ($on[0] ?? 'TX');
    }

    private function num(Request $request, string $key): int
    {
        return max(0, min(100_000_000, (int) preg_replace('/[^\d]/', '', (string) $request->query($key, '0'))));
    }

    private function pct(Request $request, string $key): float
    {
        $v = $request->query($key);

        return is_numeric($v) ? max(0.0, min(10.0, (float) $v)) : 0.0;
    }
}
