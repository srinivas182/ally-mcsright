<?php

declare(strict_types=1);

namespace Modules\Movewise\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;
use Modules\Movewise\Http\Guard;
use Modules\Movewise\I18n;
use Modules\Movewise\Program\Seats;

/**
 * Movewise's "For agents" page (specs/movewise/03): how the program works, what it costs, and the request for a free
 * Movewise Agent seat for agents who aren't on RightAlly. Works without JavaScript; no session is used, so errors are
 * shown on the page the form posts to.
 */
final class JoinController
{
    public function __construct(private readonly SiteController $site) {}

    public function show(Request $request): Response
    {
        return $this->page($request->query('sent') === '1', [], []);
    }

    public function join(Request $request, Seats $seats): Response|RedirectResponse
    {
        Guard::check($request, 'join:'.$request->ip(), 10);
        try {
            $seats->request($request->all(), (string) $request->ip());
        } catch (ValidationException $e) {
            return $this->page(false, array_map(fn (array $m): string => (string) $m[0], $e->errors()), $request->only(['first_name', 'last_name', 'email', 'state', 'licence_number', 'brokerage_name']), 422);
        }

        return redirect(I18n::url('/agents').'?sent=1', 303);
    }

    /**
     * @param  array<string, string>  $errors
     * @param  array<string, mixed>  $old
     */
    private function page(bool $sent, array $errors, array $old, int $status = 200): Response
    {
        $chrome = $this->site->chrome();

        return response()->view('movewise::agents', ['site' => $chrome, 'sent' => $sent, 'errors_' => $errors, 'old' => $old,
            'states' => config('movewise.launch_states'),
            'seo' => $this->site->seo($chrome, '/agents', 'For real estate agents | Movewise Homes', 'Get introductions to people who chose you. No shared leads, no cold calling: pay only when someone picks you and you accept.',
                ! $sent && $errors === [], [], [
                    ['How do agents get leads from Movewise?', 'People choose one of three local agents and tap Connect. Only that agent is introduced, and only after accepting. Leads are never shared or resold.'],
                    ['What does Movewise cost agents?', 'Movewise charges per introduction, per held appointment, or a referral share at closing, depending on the state. You set your areas, price range and a monthly cap.'],
                    ['Do I need to be a RightAlly customer?', 'No. Agents who aren\'t on RightAlly get a free Movewise Agent seat: contacts, tasks, inbox and calendar, so introductions and updates work.'],
                ], [['For agents', '/agents']])], $status)->header('Cache-Control', $status === 200 && ! $sent ? 'public, max-age=60, s-maxage=300' : 'no-store');
    }
}
