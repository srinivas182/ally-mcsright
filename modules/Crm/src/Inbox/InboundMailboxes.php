<?php

declare(strict_types=1);

namespace Modules\Crm\Inbox;

use Closure;

/**
 * Other modules' inbound email addresses on the one inbound webhook (LM1): "{prefix}-{token}@inbound…". Each
 * address token is registered in crm_inbound_addresses with kind = the prefix, so the webhook can find the
 * brokerage; the handler then runs as that brokerage. Transactions uses "deal-" for each deal's email-in address.
 */
final class InboundMailboxes
{
    /** @var array<string, Closure(string, array{from: string, subject: string, text: string, message_id: string|null, attachments: list<array{name: string, type: string, content: string}>}): string> */
    private array $handlers = [];

    /**
     * @param  Closure(string, array{from: string, subject: string, text: string, message_id: string|null, attachments: list<array{name: string, type: string, content: string}>}): string  $handler  token, email → status
     */
    public function register(string $prefix, Closure $handler): void
    {
        $this->handlers[$prefix] = $handler;
    }

    /**
     * @return array<string, Closure(string, array{from: string, subject: string, text: string, message_id: string|null, attachments: list<array{name: string, type: string, content: string}>}): string>
     */
    public function all(): array
    {
        return $this->handlers;
    }

    public static function address(string $prefix, string $token): string
    {
        return $prefix.'-'.$token.'@'.config('crm.inbound_email_domain', 'inbound.rightally.app');
    }
}
