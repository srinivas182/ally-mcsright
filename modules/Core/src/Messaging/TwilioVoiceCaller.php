<?php

declare(strict_types=1);

namespace Modules\Core\Messaging;

use Illuminate\Http\Client\Factory as Http;
use RuntimeException;

/** Twilio Calls API with inline TwiML: the message is read twice, so nothing needs to be hosted for Twilio. */
final class TwilioVoiceCaller implements VoiceCaller
{
    public function __construct(private readonly Http $http, private readonly string $sid, private readonly string $token, private readonly string $from) {}

    public function call(string $to, string $message): string
    {
        $say = htmlspecialchars($message, ENT_XML1 | ENT_QUOTES);
        $twiml = "<Response><Say voice=\"Polly.Joanna\">{$say}</Say><Pause length=\"1\"/><Say voice=\"Polly.Joanna\">Again: {$say}</Say></Response>";

        return $this->place($to, $twiml);
    }

    public function connect(string $to, string $message, string $onPress, string $voice = 'Polly.Joanna'): string
    {
        $say = htmlspecialchars($message, ENT_XML1 | ENT_QUOTES);
        $action = htmlspecialchars($onPress, ENT_XML1 | ENT_QUOTES);
        $v = htmlspecialchars($voice, ENT_XML1 | ENT_QUOTES);
        $gather = "<Gather numDigits=\"1\" timeout=\"8\" action=\"{$action}\" method=\"POST\"><Say voice=\"{$v}\">{$say}</Say></Gather>";

        $bye = in_array($voice, ['Polly.Lupe', 'Polly.Miguel', 'Polly.Penelope'], true) ? 'Adiós.' : 'Goodbye.';

        return $this->place($to, "<Response>{$gather}{$gather}<Say voice=\"{$v}\">{$bye}</Say></Response>");
    }

    public function bridge(string $agentPhone, string $onAnswer): string
    {
        return $this->place($agentPhone, null, $onAnswer);
    }

    public function recording(string $recordingSid): string
    {
        $r = $this->http->withBasicAuth($this->sid, $this->token)->timeout(60)
            ->get("https://api.twilio.com/2010-04-01/Accounts/{$this->sid}/Recordings/".rawurlencode($recordingSid).'.wav');

        return $r->successful() ? $r->body() : '';
    }

    public function deleteRecording(string $recordingSid): void
    {
        $this->http->withBasicAuth($this->sid, $this->token)->timeout(15)
            ->delete("https://api.twilio.com/2010-04-01/Accounts/{$this->sid}/Recordings/".rawurlencode($recordingSid).'.json');
    }

    /** Places the call; Twilio reports its real length to the status callback (for call-minute allowances). */
    private function place(string $to, ?string $twiml, ?string $url = null): string
    {
        $host = (string) config('core.hq_host', '');
        $callback = (string) (config('services.twilio.status_callback') ?: ($host !== '' ? 'https://'.$host.'/billing/twilio/call-status' : ''));
        $response = $this->http->asForm()->withBasicAuth($this->sid, $this->token)->timeout(15)
            ->post("https://api.twilio.com/2010-04-01/Accounts/{$this->sid}/Calls.json", array_filter(['To' => $to, 'From' => $this->from, 'Twiml' => $twiml, 'Url' => $url,
                'StatusCallback' => $callback !== '' ? $callback : null, 'StatusCallbackEvent' => $callback !== '' ? 'completed' : null]));
        if ($response->failed()) {
            throw new RuntimeException('The call could not be placed ('.$response->status().').');
        }

        return (string) $response->json('sid', '');
    }
}
