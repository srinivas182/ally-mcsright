<?php

declare(strict_types=1);

namespace Modules\Crm\Inbox;

use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;
use Modules\Core\Tenancy\TenantContext;
use Modules\Crm\CrmAccess;
use Modules\Crm\FairHousing;
use Modules\Crm\Models\Contact;
use Modules\Crm\Models\Lead;
use Modules\Crm\Models\Template;

/** Message templates with merge fields (specs/grow/05): shared by owners and staff, or personal to an agent. */
final class Templates
{
    public const FIELDS = ['first_name', 'last_name', 'agent_first_name', 'agent_name', 'agent_phone', 'brokerage', 'property'];

    public function __construct(private readonly TenantContext $tenants) {}

    /**
     * @return Collection<int, Template>
     */
    public function for(User $user): Collection
    {
        return Template::query()->where(fn ($q) => $q->whereNull('user_id')->orWhere('user_id', $user->id))->orderBy('name')->get();
    }

    /**
     * @param  array<string, mixed>  $in
     */
    public function save(array $in, User $by, ?Template $template = null): Template
    {
        $shared = (bool) ($in['shared'] ?? false);
        $channel = (string) ($in['channel'] ?? '');
        $body = trim((string) ($in['body'] ?? ''));
        $errors = [];
        if (trim((string) ($in['name'] ?? '')) === '') {
            $errors['name'] = 'Name the template.';
        }
        if (! in_array($channel, ['sms', 'email'], true)) {
            $errors['channel'] = 'Choose text or email.';
        }
        if ($body === '') {
            $errors['body'] = 'Write the message.';
        } elseif (($flags = FairHousing::flags($body)) !== []) {
            $errors['body'] = 'Fair Housing: reword "'.implode('", "', $flags).'".';
        }
        if (preg_match_all('/\{(\w+)\}/', $body.' '.($in['subject'] ?? ''), $m) > 0 && ($bad = array_diff($m[1], self::FIELDS)) !== []) {
            $errors['body'] = 'Unknown merge field {'.reset($bad).'}. Use: {'.implode('}, {', self::FIELDS).'}.';
        }
        if ($shared && ! CrmAccess::manager($by)) {
            $errors['shared'] = 'Only owners and staff can share templates with the brokerage.';
        }
        if ($template !== null && ! ($template->user_id === $by->id || ($template->user_id === null && CrmAccess::manager($by)))) {
            $errors['name'] = 'You cannot change this template.';
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
        $template ??= new Template;
        $template->fill(['user_id' => $shared ? null : $by->id, 'name' => mb_substr(trim((string) $in['name']), 0, 80), 'channel' => $channel,
            'subject' => $channel === 'email' ? mb_substr(trim((string) ($in['subject'] ?? '')), 0, 200) : null, 'body' => mb_substr($body, 0, 5000)])->save();

        return $template;
    }

    public function delete(Template $template, User $by): void
    {
        if (! ($template->user_id === $by->id || ($template->user_id === null && CrmAccess::manager($by)))) {
            throw ValidationException::withMessages(['template' => 'You cannot delete this template.']);
        }
        $template->delete();
    }

    public function render(string $text, Contact $contact, User $agent): string
    {
        $lead = Lead::query()->where('contact_id', $contact->id)->whereNotNull('property')->latest('id')->first();
        $values = ['first_name' => $contact->first_name, 'last_name' => $contact->last_name, 'agent_first_name' => $agent->first_name, 'agent_name' => $agent->name,
            'agent_phone' => (string) $agent->phone, 'brokerage' => $this->tenants->current()->name, 'property' => $lead !== null ? (string) $lead->property : 'the home'];

        return (string) preg_replace_callback('/\{(\w+)\}/', fn (array $m): string => $values[$m[1]] ?? $m[0], $text);
    }
}
