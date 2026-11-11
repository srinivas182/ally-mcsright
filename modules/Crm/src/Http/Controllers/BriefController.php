<?php

declare(strict_types=1);

namespace Modules\Crm\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Modules\AI\Exceptions\AiOutputBlocked;
use Modules\AI\Exceptions\AllModelsFailed;
use Modules\AI\Exceptions\InsufficientCredits;
use Modules\AI\Exceptions\UnknownFeature;
use Modules\Crm\ContactBriefs;
use Modules\Crm\ContactVisibility;
use Modules\Crm\Models\Contact;
use Modules\Crm\Models\ContactBrief;

final class BriefController
{
    public function __construct(private readonly ContactVisibility $visibility, private readonly ContactBriefs $briefs) {}

    public function refresh(Request $request, Contact $contact): RedirectResponse
    {
        $user = self::user($request);
        abort_unless($this->visibility->canSee($user, $contact), 404);

        try {
            $this->briefs->generate($contact, $user);
        } catch (InsufficientCredits) {
            Inertia::flash('toast', ['type' => 'error', 'message' => 'Out of AI credits. Ask your broker to top up.']);
        } catch (UnknownFeature|AllModelsFailed) {
            Inertia::flash('toast', ['type' => 'error', 'message' => 'The AI brief is unavailable right now. Please try again shortly.']);
        } catch (AiOutputBlocked) {
            Inertia::flash('toast', ['type' => 'error', 'message' => 'The brief was withheld by the safety check. Please try again.']);
        }

        return back();
    }

    public function feedback(Request $request, Contact $contact): RedirectResponse
    {
        abort_unless($this->visibility->canSee(self::user($request), $contact), 404);
        $data = $request->validate(['helpful' => ['required', 'boolean']]);
        $brief = ContactBrief::query()->where('contact_id', $contact->id)->firstOrFail();
        $this->briefs->feedback($brief, (bool) $data['helpful']);

        return back();
    }

    private static function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
