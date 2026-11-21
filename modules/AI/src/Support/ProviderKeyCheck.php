<?php

declare(strict_types=1);

namespace Modules\AI\Support;

use Illuminate\Http\Client\Factory as Http;
use Modules\AI\Models\AiProvider;
use Throwable;

/**
 * AI1: "Check connection" for an AI provider's key. Asks the provider for its list of models, which costs nothing and
 * proves the key is accepted. Never sends brokerage data.
 */
final class ProviderKeyCheck
{
    public function __construct(private readonly Http $http) {}

    /** Null when the provider accepts the key; otherwise what went wrong, in plain words. */
    public function check(AiProvider $provider, ?string $key = null): ?string
    {
        $key ??= $provider->apiKey();
        if ($key === null || $key === '') {
            return 'No key saved yet.';
        }
        $base = rtrim($provider->base_url, '/');
        $client = $this->http->timeout(15)->acceptJson();

        try {
            $response = match ($provider->adapter) {
                'anthropic' => $client->withHeaders(['x-api-key' => $key, 'anthropic-version' => '2023-06-01'])->get($base.'/v1/models'),
                'gemini' => $client->withHeaders(['x-goog-api-key' => $key])->get($base.'/v1beta/models'),
                default => $client->withToken($key)->get($base.'/v1/models'),
            };
        } catch (Throwable) {
            return 'Could not reach '.$provider->name.'. Try again in a minute.';
        }

        return match (true) {
            $response->successful() => null,
            in_array($response->status(), [400, 401, 403], true) => $provider->name.' did not accept this key. Check you copied all of it.',
            default => $provider->name.' answered with an error ('.$response->status().'). Try again in a minute.',
        };
    }
}
