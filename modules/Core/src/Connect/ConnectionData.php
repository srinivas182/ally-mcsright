<?php

declare(strict_types=1);

namespace Modules\Core\Connect;

/** What a connector driver gets about one connection (decrypted credentials stay in memory only). */
final readonly class ConnectionData
{
    /**
     * @param  array<string, string>  $credentials
     * @param  array<string, mixed>  $settings
     */
    public function __construct(
        public int $id,
        public string $ulid,
        public string $appKey,
        public ?int $ownerUserId,
        public array $credentials,
        public array $settings,
        public ?string $cursor,
    ) {}
}
