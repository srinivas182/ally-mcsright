<?php

declare(strict_types=1);

namespace Modules\Core\Messaging;

use Illuminate\Http\Client\Factory as Http;
use RuntimeException;

/** Twilio Calls API with inline TwiML: the message is read twice, so nothing needs to be hosted for Twilio. */
final class TwilioVoiceCaller implements VoiceCaller
{
    public function __construct(private readonly Http $http, private readonly string $sid, private readonly string $token, private readonly string $from) {}

    public function call(string $to, string $message): void
    {
        $say = htmlspecialchars($message, ENT_XML1 | ENT_QUOTES);
        $twiml = "<Response><Say voice=\"Polly.Joanna\">{$say}</Say><Pause length=\"1\"/><Say voice=\"Polly.Joanna\">Again: {$say}</Say></Response>";
        $this->place($to, $twiml);
    }

    public function connect(string $to, string $message, string $onPress): void
    {
        $say = htmlspecialchars($message, ENT_XML1 | ENT_QUOTES);
        $action = htmlspecialchars($onPress, ENT_XML1 | ENT_QUOTES);
        $gather = "<Gather numDigits=\"1\" timeout=\"8\" action=\"{$action}\" method=\"POST\"><Say voice=\"Polly.Joanna\">{$say}</Say></Gather>";
        $this->place($to, "<Response>{$gather}{$gather}<Say voice=\"Polly.Joanna\">Goodbye.</Say></Response>");
    }

    private function place(string $to, string $twiml): void
    {
        $response = $this->http->asForm()->withBasicAuth($this->sid, $this->token)->timeout(15)
            ->post("https://api.twilio.com/2010-04-01/Accounts/{$this->sid}/Calls.json", ['To' => $to, 'From' => $this->from, 'Twiml' => $twiml]);
        if ($response->failed()) {
            throw new RuntimeException('The call could not be placed ('.$response->status().').');
        }
    }
}
