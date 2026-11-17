<?php

declare(strict_types=1);

namespace Modules\ListIq\Http;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;
use Modules\ListIq\Models\Prep;
use Modules\ListIq\Showings;

/** The three feedback questions after a showing (SH1). */
final class FeedbackController
{
    public function handle(Request $request, string $token, Showings $showings): Response
    {
        $s = $showings->byToken($token);
        $prep = $s !== null ? Prep::query()->find($s->listing_id) : null;
        $error = null;
        if ($s !== null && $request->isMethod('post') && $s->feedback_at === null) {
            try {
                $showings->feedback($s, $request->only(['interest', 'price', 'comments']));
            } catch (ValidationException $e) {
                $error = (string) collect($e->errors())->flatten()->first();
            }
        }

        return response()->view('listiq::feedback', ['showing' => $s?->fresh(), 'prep' => $prep, 'error' => $error], $s === null ? 404 : 200)
            ->withHeaders(['X-Robots-Tag' => 'noindex, nofollow', 'Cache-Control' => 'private, no-store', 'Referrer-Policy' => 'no-referrer']);
    }
}
