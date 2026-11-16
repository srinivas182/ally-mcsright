<?php

declare(strict_types=1);

namespace Modules\Crm\Http\Controllers\Leads;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Modules\Crm\Actions\Timeline;
use Modules\Crm\Booking\Availability;
use Modules\Crm\Leads\AiCaller;
use Modules\Crm\Leads\AiResponder;
use Modules\Crm\Leads\LeadAlert;
use Modules\Crm\Leads\SpeedToLead;
use Modules\Crm\Models\Contact;
use Modules\Crm\Models\Lead;
use stdClass;

/**
 * Lead-IQ calls (LQ1): Twilio's keypad steps (signed, 30-minute links), and the page where the person picks a time
 * with the agent (signed, 7-day link).
 */
final class AiCallController
{
    public function step(Request $request, string $call, string $step, AiCaller $caller): Response
    {
        abort_unless($request->hasValidSignature(false), 403);

        return response($caller->twiml($call, $step, $request->only(['Digits', 'DialCallStatus'])), 200, ['Content-Type' => 'text/xml']);
    }

    public function book(Request $request, string $call, AiCaller $caller, Availability $availability): Response
    {
        abort_unless($request->hasValidSignature(false), 403);
        [$row, $agent, $contact] = $this->parts($call, $caller);
        $slots = $agent !== null && $row->status !== 'booked' ? $availability->slots($agent, 'call', 7, 12) : [];

        return response()->view('crm::leads.book', [
            'brand' => app(AiResponder::class)->settings()['brand_name'], 'lang' => (string) $row->lang, 'name' => $contact->first_name, 'booked' => $row->status === 'booked',
            'agent' => $agent?->first_name, 'slots' => array_map(fn (CarbonImmutable $s): array => ['value' => $s->toIso8601String(),
                'label' => self::when($s->setTimezone($availability->timezone()), (string) $row->lang)], $slots),
            'action' => $request->fullUrl(),
        ])->header('X-Robots-Tag', 'noindex, nofollow')->header('Cache-Control', 'private, no-store')->header('Referrer-Policy', 'no-referrer');
    }

    public function confirm(Request $request, string $call, AiCaller $caller, Availability $availability, Timeline $timeline): RedirectResponse
    {
        abort_unless($request->hasValidSignature(false), 403);
        [$row, $agent, $contact] = $this->parts($call, $caller);
        abort_if($agent === null || $row->status === 'booked', 410);
        $data = $request->validate(['slot' => ['required', 'date']]);
        $start = CarbonImmutable::parse((string) $data['slot']);
        $availability->book($agent, $contact, $start, 'call', 'Booked from the link the AI assistant sent after its call.');
        $caller->update($row, ['status' => 'booked', 'agent_id' => $agent->id]);
        $when = self::when($start->setTimezone($availability->timezone()), 'en');
        $timeline->system($contact, "Booked a call with {$agent->name} for {$when} (link from the AI assistant).");
        $lead = Lead::query()->find((int) $row->lead_id);
        $agent->notify(new LeadAlert("Booked: {$contact->name}", ($lead !== null ? AiCaller::handoff($lead, $contact).' ' : '')."Call booked for {$when}.",
            app(SpeedToLead::class)->link('/contacts/'.$contact->ulid), 'Open the contact'));

        return redirect()->to($request->fullUrl());
    }

    /** A time in words, in the person's language ("Tuesday 2 June, 10:00 am" / "martes 2 junio, 10:00 a. m."). */
    private static function when(CarbonImmutable $at, string $lang): string
    {
        $local = $at->locale($lang === 'es' ? 'es' : 'en');

        return ($local instanceof CarbonImmutable ? $local : $at)->isoFormat('dddd D MMMM, h:mm a');
    }

    /** @return array{0: stdClass, 1: User|null, 2: Contact} */
    private function parts(string $call, AiCaller $caller): array
    {
        $row = $caller->find($call) ?? abort(404);
        $contact = Contact::query()->find((int) $row->contact_id) ?? abort(404);
        $agents = (array) json_decode((string) $row->agents, true);
        $agentId = $row->agent_id ?? ($agents[0] ?? null);

        return [$row, $agentId !== null ? User::query()->find((int) $agentId) : null, $contact];
    }
}
