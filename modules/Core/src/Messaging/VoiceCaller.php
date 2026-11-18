<?php

declare(strict_types=1);

namespace Modules\Core\Messaging;

/** Places an automated voice call that reads a short message aloud. */
interface VoiceCaller
{
    /** Returns the provider's call id ('' when no call was placed), used to settle the real call minutes. */
    public function call(string $to, string $message): string;

    /**
     * Reads the message and asks the person to press 1; when they do, Twilio fetches TwiML from $onPress
     * (which connects them to someone). Voicemail cannot press 1, so a missed call never connects anyone.
     */
    public function connect(string $to, string $message, string $onPress, string $voice = 'Polly.Joanna'): string;

    /**
     * VO1 (specs/grow/14): calls the agent first; when they answer, the provider fetches TwiML from $onAnswer (which
     * announces the recording and dials the client). Returns the call id ('' when no call was placed).
     */
    public function bridge(string $agentPhone, string $onAnswer): string;

    /** The audio of a finished recording (empty when there is none). */
    public function recording(string $recordingSid): string;

    /** Deletes a recording from the provider once it has been written down. */
    public function deleteRecording(string $recordingSid): void;
}
