<?php

declare(strict_types=1);

namespace Modules\Crm\Voice;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Writes down recorded calls through the transcripts provider HQ chose in Outside services (VO1, specs/grow/14):
 * Deepgram (recommended), AssemblyAI or ElevenLabs Scribe. The audio is sent once and not kept by RightAlly; English
 * and Spanish are detected by the provider, and speakers are told apart.
 *
 * Deepgram and ElevenLabs answer straight away; AssemblyAI works in the background, so start() returns its job id and
 * fetch() is asked again later.
 */
final class Transcriber
{
    /**
     * @param  array<string, string>  $keys
     * @return array{text: string|null, job: string|null}
     */
    public function start(string $provider, array $keys, string $audio, string $mime = 'audio/wav'): array
    {
        if ($audio === '') {
            throw new RuntimeException('There was no recording to write down.');
        }

        switch ($provider) {
            case 'deepgram':
                $r = Http::timeout(120)->withHeaders(['Authorization' => 'Token '.$keys['api_key']])->withBody($audio, $mime)
                    ->post('https://api.deepgram.com/v1/listen?model=nova-3&smart_format=true&diarize=true&detect_language=true&utterances=true');
                $this->ok($r->failed(), $r->status());

                return ['text' => self::speakers((array) $r->json('results.utterances', []), 'speaker', 'transcript')
                    ?: (string) $r->json('results.channels.0.alternatives.0.transcript'), 'job' => null];
            case 'elevenlabs':
                $r = Http::timeout(120)->withHeaders(['xi-api-key' => $keys['api_key']])->attach('file', $audio, 'recording.'.(str_contains($mime, 'webm') ? 'webm' : (str_contains($mime, 'ogg') ? 'ogg' : (str_contains($mime, 'mp4') ? 'm4a' : 'wav'))))
                    ->post('https://api.elevenlabs.io/v1/speech-to-text', ['model_id' => 'scribe_v1', 'diarize' => 'true']);
                $this->ok($r->failed(), $r->status());

                return ['text' => (string) $r->json('text'), 'job' => null];
            case 'assemblyai':
                $h = Http::timeout(120)->withHeaders(['authorization' => $keys['api_key']]);
                $up = $h->withBody($audio, 'application/octet-stream')->post('https://api.assemblyai.com/v2/upload');
                $this->ok($up->failed(), $up->status());
                $r = Http::timeout(30)->withHeaders(['authorization' => $keys['api_key']])->post('https://api.assemblyai.com/v2/transcript',
                    ['audio_url' => (string) $up->json('upload_url'), 'speaker_labels' => true, 'language_detection' => true]);
                $this->ok($r->failed(), $r->status());

                return ['text' => null, 'job' => (string) $r->json('id')];
            default:
                throw new RuntimeException('Unknown transcripts provider.');
        }
    }

    /**
     * A background job's text, once ready (null while still working).
     *
     * @param  array<string, string>  $keys
     */
    public function fetch(string $provider, array $keys, string $job): ?string
    {
        if ($provider !== 'assemblyai') {
            return null;
        }
        $r = Http::timeout(30)->withHeaders(['authorization' => $keys['api_key']])->get('https://api.assemblyai.com/v2/transcript/'.rawurlencode($job));
        $this->ok($r->failed(), $r->status());
        $status = (string) $r->json('status');
        if ($status === 'error') {
            throw new RuntimeException('The transcripts provider could not write this call down.');
        }

        return $status === 'completed' ? (self::speakers((array) $r->json('utterances', []), 'speaker', 'text') ?: (string) $r->json('text')) : null;
    }

    /**
     * "Speaker 1: …" lines from the provider's utterances.
     *
     * @param  array<int, mixed>  $utterances
     */
    private static function speakers(array $utterances, string $who, string $text): string
    {
        $lines = [];
        foreach ($utterances as $u) {
            if (is_array($u) && isset($u[$text])) {
                $lines[] = 'Speaker '.(is_numeric($u[$who] ?? null) ? ((int) $u[$who]) + 1 : (string) ($u[$who] ?? '?')).': '.trim((string) $u[$text]);
            }
        }

        return implode("\n", $lines);
    }

    private function ok(bool $failed, int $status): void
    {
        if ($failed) {
            throw new RuntimeException('The transcripts provider did not answer ('.$status.').');
        }
    }
}
