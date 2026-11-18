<?php

declare(strict_types=1);

namespace Modules\Learn\Http;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Core\Tenancy\TenantContext;
use Modules\Learn\MarketQuiz;
use Modules\Learn\Pods;
use Modules\Listings\Search\MarketStats;

/** LN4 (E20): local market quizzes and accountability pods. */
final class MasteryController
{
    public function market(Request $request, MarketQuiz $quiz, MarketStats $stats, TenantContext $tenants): Response
    {
        $user = self::user($request);
        $places = array_map(fn (array $p): array => ['slug' => $p['slug'], 'name' => $p['name']], $stats->places($tenants->id(), 40));
        $place = is_string($request->query('place')) ? $request->query('place') : ($places[0]['slug'] ?? null);

        return Inertia::render('learn/market', ['places' => $places, 'place' => $place, 'quiz' => $place !== null ? $quiz->today($place) : null,
            'streak' => $quiz->streak($user), 'result' => $request->session()->get('market_result')]);
    }

    public function answer(Request $request, MarketQuiz $quiz): RedirectResponse
    {
        $data = $request->validate(['place' => ['required', 'string', 'max:80'], 'answers' => ['required', 'array'], 'answers.*' => ['nullable', 'string', 'max:40']]);
        $result = $quiz->mark(self::user($request), (string) $data['place'], array_map('strval', array_filter((array) $data['answers'], 'is_string')));
        $request->session()->flash('market_result', $result);

        return redirect('/learn/market?place='.rawurlencode((string) $data['place']));
    }

    public function pods(Request $request, Pods $pods): Response
    {
        $user = self::user($request);

        return Inertia::render('learn/pods', ['pods' => $pods->mine($user), 'people' => $pods->people($user), 'min' => Pods::MIN, 'max' => Pods::MAX]);
    }

    public function createPod(Request $request, Pods $pods): RedirectResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:80'], 'members' => ['required', 'array', 'max:5'], 'members.*' => ['string', 'size:26']]);

        return redirect('/learn/pods/'.$pods->create(self::user($request), (string) $data['name'], array_values(array_map('strval', $data['members']))));
    }

    public function pod(Request $request, Pods $pods, string $pod): Response
    {
        return Inertia::render('learn/pod', ['pod' => $pods->show($pod, self::user($request))]);
    }

    public function checkIn(Request $request, Pods $pods, string $pod): RedirectResponse
    {
        $data = $request->validate(['goal' => ['required', 'string', 'max:300'], 'done' => ['nullable', 'string', 'max:300'], 'help' => ['nullable', 'string', 'max:300']]);
        $pods->checkIn(self::user($request), $pod, (string) $data['goal'], $data['done'] ?? null, $data['help'] ?? null);

        return back();
    }

    public function leave(Request $request, Pods $pods, string $pod): RedirectResponse
    {
        $pods->leave(self::user($request), $pod);

        return redirect('/learn/pods');
    }

    private static function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
