<?php

declare(strict_types=1);

namespace Modules\Outreach;

use App\Models\User;
use Illuminate\Validation\ValidationException;
use Modules\Core\Audit\AuditLogger;
use Modules\Core\Tenancy\TenantContext;
use Modules\Crm\Models\Contact;
use Modules\Outreach\Models\CardRequest;

/**
 * Handwritten cards (MK2, specs/crm/21): an agent writes a short note to a contact (a birthday, a home anniversary,
 * a thank-you) and a handwriting partner writes it with a real pen and mails it. Requests queue here with the address
 * as it was when sent; HQ sends the batch to the partner (CSV in the partner's upload format) and marks them mailed.
 * The partner and its price per card are set by HQ (default price marked for review).
 */
final class HandwrittenCards
{
    public const OCCASIONS = ['birthday' => 'Happy birthday', 'home_anniversary' => 'Home anniversary', 'thank_you' => 'Thank you', 'just_because' => 'Thinking of you'];

    public const MAX_CHARS = 320;

    public function __construct(private readonly TenantContext $tenants) {}

    public function request(User $by, Contact $contact, string $occasion, string $message): void
    {
        $message = trim($message);
        if (! isset(self::OCCASIONS[$occasion])) {
            throw ValidationException::withMessages(['occasion' => 'Choose the occasion.']);
        }
        if (mb_strlen($message) < 10 || mb_strlen($message) > self::MAX_CHARS) {
            throw ValidationException::withMessages(['message' => 'Between 10 and '.self::MAX_CHARS.' characters, so it fits on the card.']);
        }
        if (($contact->address_line1 ?? '') === '' || ($contact->city ?? '') === '' || ($contact->state ?? '') === '' || ($contact->zip ?? '') === '') {
            throw ValidationException::withMessages(['contact' => 'Add their full mailing address to the contact first.']);
        }
        CardRequest::query()->create(['user_id' => $by->id, 'contact_id' => $contact->id,
            'occasion' => $occasion, 'message' => $message, 'to_name' => trim($contact->first_name.' '.$contact->last_name), 'to_line1' => (string) $contact->address_line1,
            'to_line2' => $contact->address_line2, 'to_city' => (string) $contact->city, 'to_state' => (string) $contact->state, 'to_zip' => (string) $contact->zip,
            'from_name' => $by->name, 'from_company' => $this->tenants->current()->name, 'status' => 'queued']);
        app(AuditLogger::class)->record('outreach.card_requested', $contact, metadata: ['occasion' => $occasion]);
    }

    /**
     * @return list<array{id: int, contact: string, occasion: string, message: string, status: string, created_at: string}>
     */
    public function forUser(User $user): array
    {
        return array_values(CardRequest::query()->where('user_id', $user->id)->orderByDesc('id')->limit(100)->get()
            ->map(fn (CardRequest $r): array => ['id' => $r->id, 'contact' => $r->to_name, 'occasion' => self::OCCASIONS[$r->occasion] ?? $r->occasion,
                'message' => $r->message, 'status' => $r->status, 'created_at' => (string) $r->created_at?->toDateString()])->all());
    }
}
