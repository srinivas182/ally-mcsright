<?php

declare(strict_types=1);

namespace Modules\Core\Messaging;

use Illuminate\Http\Client\Factory as Http;
use RuntimeException;

final class TwilioSmsSender implements SmsSender
{
    public function __construct(private readonly Http $http, private readonly string $sid, private readonly string $token, private readonly string $from) {}

    public function send(string $to, string $message): void
    {
        $this->deliver($this->from, $to, $message);
    }

    public function deliver(string $from, string $to, string $message): string
    {
        $response = $this->http->asForm()->withBasicAuth($this->sid, $this->token)->timeout(15)
            ->post("https://api.twilio.com/2010-04-01/Accounts/{$this->sid}/Messages.json", ['To' => $to, 'From' => $from !== '' ? $from : $this->from, 'Body' => $message]);
        if ($response->failed()) {
            throw new RuntimeException('The text message could not be sent ('.$response->status().').');
        }

        return (string) $response->json('sid', '');
    }
}
