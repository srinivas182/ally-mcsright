<?php

declare(strict_types=1);

namespace Modules\Core\Identity\Auth;

use Illuminate\Auth\Passwords\PasswordBrokerManager;
use Illuminate\Auth\Passwords\TokenRepositoryInterface;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Hashing\Hasher;
use Illuminate\Database\DatabaseManager;

final class TenantPasswordBrokerManager extends PasswordBrokerManager
{
    /**
     * @param  array<string, mixed>  $config
     */
    protected function createTokenRepository(array $config): TokenRepositoryInterface
    {
        /** @var Repository $appConfig */
        $appConfig = $this->app->make('config');
        $key = (string) $appConfig->get('app.key');

        if (str_starts_with($key, 'base64:')) {
            $key = (string) base64_decode(substr($key, 7));
        }

        /** @var DatabaseManager $db */
        $db = $this->app->make('db');
        /** @var Hasher $hasher */
        $hasher = $this->app->make('hash');

        return new TenantPasswordTokenRepository(
            $db->connection($config['connection'] ?? null),
            $hasher,
            (string) $config['table'],
            $key,
            ((int) ($config['expire'] ?? 60)) * 60,
            (int) ($config['throttle'] ?? 0),
        );
    }
}
