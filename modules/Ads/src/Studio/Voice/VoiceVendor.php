<?php

declare(strict_types=1);

namespace Modules\Ads\Studio\Voice;

/**
 * The AI voice service behind Reel and tour narration (specs/grow/13, SA5b): professional stock voices for everyone,
 * and the agent's own voice only with their recorded, written consent.
 */
interface VoiceVendor
{
    /** @return list<array{id: string, name: string, accent: string}> stock voices licensed for commercial use */
    public function stock(): array;

    /**
     * Makes a voice from the agent's own recordings and returns the vendor's id for it. The recordings are not kept.
     *
     * @param  list<string>  $files  audio files (the agent reading the consent statement and the sample script)
     */
    public function cloneVoice(string $label, array $files): string;

    /** @return string MP3 audio of the text in that voice */
    public function speak(string $voiceId, string $text): string;

    /** Deletes the agent's voice from the vendor for good. */
    public function delete(string $voiceId): void;
}
