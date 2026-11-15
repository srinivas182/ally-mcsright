<?php

declare(strict_types=1);

namespace Modules\Crm\Mcp;

use App\Models\User;
use Modules\Crm\Models\Contact;
use Modules\Crm\Models\Stage;

/** How CRM records look to an AI assistant: what an agent sees on screen, nothing more (no internal ids). */
final class CrmMcpData
{
    /** @return array<string, mixed> */
    public static function contact(Contact $c): array
    {
        $owner = $c->owner_user_id !== null ? User::query()->find($c->owner_user_id) : null;

        return ['id' => $c->ulid, 'name' => trim($c->first_name.' '.$c->last_name), 'company' => $c->company_name, 'email' => $c->primary_email, 'phone' => $c->primary_phone,
            'city' => $c->city, 'state' => $c->state, 'lifecycle' => $c->lifecycle->value, 'source' => $c->source->value,
            'stage' => $c->stage_id !== null ? Stage::query()->whereKey($c->stage_id)->value('name') : null,
            'owner' => $owner instanceof User ? $owner->name : null, 'last_activity' => $c->last_activity_at?->toDateString(),
            'can_text' => $c->primary_phone !== null && $c->sms_opt_out_at === null, 'link' => url('/contacts/'.$c->ulid)];
    }
}
