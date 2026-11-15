<?php

declare(strict_types=1);

namespace Modules\Crm\Connect;

use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Core\Connect\ConnectionData;
use Modules\Core\Connect\ConnectorKit;
use Modules\Crm\Enums\Source;
use Modules\Crm\Leads\LeadIntake;
use Modules\Crm\Models\LeadSource;

/**
 * Leads from connected lead-source apps (specs/connect/10, 11) go through lead intake like every other lead, under
 * one lead source per app (made once per brokerage), once only (by the other app's lead id). A brokerage connection's
 * leads follow its routing rules; an agent's connection's leads are theirs. Texting consent is never assumed.
 */
final class ConnectedLeads
{
    public function __construct(private readonly ConnectorKit $kit) {}

    /**
     * @param  array<string, mixed>  $in  lead intake fields; must include external_id
     * @return bool whether it was taken in (false: no name, or no email or phone)
     */
    public function receive(ConnectionData $connection, string $sourceName, Source $source, array $in): bool
    {
        $by = User::query()->find($this->kit->connectedBy($connection));
        $assign = $connection->ownerUserId !== null ? User::query()->find($connection->ownerUserId) : null;
        $row = LeadSource::query()->where('name', $sourceName)->where('source', $source->value)->first()
            ?? LeadSource::query()->create(['name' => $sourceName, 'kind' => 'api', 'source' => $source, 'token' => Str::random(40), 'created_by' => $by?->id]);
        try {
            app(LeadIntake::class)->receive($row, $in + ['source' => $source->value], null, $assign instanceof User ? $assign : null);
        } catch (ValidationException) {
            return false;
        }

        return true;
    }
}
