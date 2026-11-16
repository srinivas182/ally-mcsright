<?php

declare(strict_types=1);

namespace Modules\Ads\Studio\Voice;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Modules\Connect\AppRegistry;
use Modules\Core\Connect\AppCatalog;
use RuntimeException;

/**
 * ElevenLabs (app `studio_voice`, RightAlly's API key): text to speech with the multilingual model, instant voice
 * cloning from the agent's consented recordings, and deletion when consent ends. Stock voices are the ones HQ lists
 * (premade voices licensed for commercial use).
 */
final class ElevenLabs implements VoiceVendor
{
    private const BASE = 'https://api.elevenlabs.io/v1/';

    /** Premade ElevenLabs voices, cleared for commercial use; HQ can replace the list (key `stock_voices`). */
    public const STOCK = [
        ['id' => '21m00Tcm4TlvDq8ikWAM', 'name' => 'Rachel', 'accent' => 'Warm, American'],
        ['id' => 'pNInz6obpgDQGcFmaJgB', 'name' => 'Adam', 'accent' => 'Deep, American'],
        ['id' => 'EXAVITQu4vr4xnSDxMaL', 'name' => 'Sarah', 'accent' => 'Soft, American'],
        ['id' => 'TxGEqnHWrfWFTfGW9XjX', 'name' => 'Josh', 'accent' => 'Friendly, American'],
    ];

    public function stock(): array
    {
        $raw = json_decode((string) ($this->keys()['stock_voices'] ?? ''), true);
        $list = is_array($raw) ? array_values(array_filter($raw, fn ($v): bool => is_array($v) && is_string($v['id'] ?? null) && is_string($v['name'] ?? null))) : [];

        return $list !== [] ? array_map(fn (array $v): array => ['id' => (string) $v['id'], 'name' => (string) $v['name'], 'accent' => (string) ($v['accent'] ?? '')], $list) : self::STOCK;
    }

    public function cloneVoice(string $label, array $files): string
    {
        $http = $this->http()->timeout(120);
        foreach ($files as $i => $file) {
            $http = $http->attach('files', (string) file_get_contents($file), 'sample-'.($i + 1).'.'.(pathinfo($file, PATHINFO_EXTENSION) ?: 'webm'));
        }
        $r = $http->post(self::BASE.'voices/add', ['name' => mb_substr($label, 0, 100), 'remove_background_noise' => 'true',
            'description' => 'A RightAlly agent\'s own voice, for narrating their listing videos. Made with their written and recorded consent.']);
        self::check($r);

        return is_string($r->json('voice_id')) ? $r->json('voice_id') : throw new RuntimeException('The voice service did not return a voice.');
    }

    public function speak(string $voiceId, string $text): string
    {
        $r = $this->http()->timeout(120)->withHeaders(['Accept' => 'audio/mpeg'])->post(self::BASE.'text-to-speech/'.rawurlencode($voiceId).'?output_format=mp3_44100_128', [
            'text' => $text, 'model_id' => (string) ($this->keys()['model_id'] ?? '') !== '' ? (string) $this->keys()['model_id'] : 'eleven_multilingual_v2',
            'voice_settings' => ['stability' => 0.5, 'similarity_boost' => 0.8, 'style' => 0.15, 'use_speaker_boost' => true],
        ]);
        self::check($r);
        if (strlen($r->body()) < 500) {
            throw new RuntimeException('The voice service returned no audio.');
        }

        return $r->body();
    }

    public function delete(string $voiceId): void
    {
        $r = $this->http()->timeout(30)->delete(self::BASE.'voices/'.rawurlencode($voiceId));
        if (! $r->successful() && $r->status() !== 404) { // already gone is fine
            self::check($r);
        }
    }

    private function http(): PendingRequest
    {
        $key = (string) ($this->keys()['api_key'] ?? '');
        if ($key === '') {
            throw new RuntimeException('AI voice isn\'t switched on yet. Please ask RightAlly support.');
        }

        return Http::withHeaders(['xi-api-key' => $key])->acceptJson();
    }

    /** @return array<string, string> */
    private function keys(): array
    {
        $app = app(AppCatalog::class)->find('studio_voice');

        return $app !== null ? app(AppRegistry::class)->secrets($app) : [];
    }

    private static function check(Response $r): void
    {
        if ($r->successful()) {
            return;
        }
        $detail = $r->json('detail.message') ?? $r->json('detail');
        throw new RuntimeException(mb_substr('Voice service: '.(is_string($detail) ? $detail : 'error '.$r->status()), 0, 300));
    }
}
