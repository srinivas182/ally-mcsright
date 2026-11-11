<?php

declare(strict_types=1);

namespace Modules\AI\Support;

use Generator;
use Psr\Http\Message\StreamInterface;

/**
 * Reads a Server-Sent Events body incrementally and yields each event's data payload.
 */
final class SseReader
{
    /**
     * @return Generator<int, array{event: string|null, data: string}>
     */
    public static function events(StreamInterface $body): Generator
    {
        $buffer = '';
        $event = null;
        $data = [];

        while (! $body->eof()) {
            $buffer .= $body->read(8192);

            while (($newline = strpos($buffer, "\n")) !== false) {
                $line = rtrim(substr($buffer, 0, $newline), "\r");
                $buffer = substr($buffer, $newline + 1);

                if ($line === '') {
                    if ($data !== []) {
                        yield ['event' => $event, 'data' => implode("\n", $data)];
                    }
                    $event = null;
                    $data = [];

                    continue;
                }

                if (str_starts_with($line, 'event:')) {
                    $event = trim(substr($line, 6));
                } elseif (str_starts_with($line, 'data:')) {
                    $data[] = ltrim(substr($line, 5));
                }
            }
        }

        if ($data !== []) {
            yield ['event' => $event, 'data' => implode("\n", $data)];
        }
    }
}
