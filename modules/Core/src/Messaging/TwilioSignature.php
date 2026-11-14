<?php

declare(strict_types=1);

namespace Modules\Core\Messaging;

/**
 * Checks Twilio's X-Twilio-Signature: base64 HMAC-SHA1 of the full URL followed by each POST parameter's name
 * and value, sorted by name, keyed with the account's auth token.
 */
final class TwilioSignature
{
    /**
     * @param  array<string, mixed>  $params
     */
    public static function valid(string $url, array $params, string $signature, string $token): bool
    {
        if ($signature === '' || $token === '') {
            return false;
        }
        ksort($params);
        $data = $url;
        foreach ($params as $key => $value) {
            $data .= $key.(is_array($value) ? implode('', $value) : (string) $value);
        }

        return hash_equals(base64_encode(hash_hmac('sha1', $data, $token, true)), $signature);
    }

    /**
     * @param  array<string, mixed>  $params
     */
    public static function sign(string $url, array $params, string $token): string
    {
        ksort($params);
        $data = $url;
        foreach ($params as $key => $value) {
            $data .= $key.(string) $value;
        }

        return base64_encode(hash_hmac('sha1', $data, $token, true));
    }
}
