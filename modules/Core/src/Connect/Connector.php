<?php

declare(strict_types=1);

namespace Modules\Core\Connect;

use Illuminate\Validation\ValidationException;

/**
 * A connector driver (specs/connect/00): checks what the person entered when they connect, and (for syncing apps)
 * brings data in. Drivers live in the module that owns the data (CRM imports in Crm, deals in Transactions).
 */
interface Connector
{
    /**
     * Checks the credentials and settings with the other app.
     *
     * @param  array<string, string>  $credentials
     * @param  array<string, mixed>  $settings
     * @return string a label for the connected account, e.g. "jane@acme.com" or "Acme Realty (Follow Up Boss)"
     *
     * @throws ValidationException when they do not work
     */
    public function verify(array $credentials, array $settings): string;

    /**
     * One sync run. Must be safe to repeat (upsert by the other app's ids) and return what it did.
     *
     * "more" means there is more to bring over now (a big first import): the next run starts straight away.
     *
     * @return array{created: int, updated: int, skipped: int, cursor: string|null, note: string|null, more?: bool}
     */
    public function sync(ConnectionData $connection): array;
}
