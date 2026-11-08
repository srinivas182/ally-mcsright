<?php

declare(strict_types=1);

namespace Modules\AI\Retrieval;

/**
 * Splits text into overlapping chunks of roughly $size characters, preferring paragraph, then
 * sentence, then word boundaries, so each chunk reads as a coherent passage.
 */
final class Chunker
{
    public function __construct(
        private readonly int $size = 1500,
        private readonly int $overlap = 200,
    ) {}

    /**
     * @return list<string>
     */
    public function split(string $text): array
    {
        $text = trim((string) preg_replace("/[ \t]+/", ' ', str_replace("\r\n", "\n", $text)));
        if ($text === '') {
            return [];
        }

        if (mb_strlen($text) <= $this->size) {
            return [$text];
        }

        $chunks = [];
        $start = 0;
        $length = mb_strlen($text);

        while ($start < $length) {
            $end = min($start + $this->size, $length);

            if ($end < $length) {
                $window = mb_substr($text, $start, $end - $start);
                $break = $this->lastBoundary($window);
                if ($break > (int) ($this->size * 0.5)) {
                    $end = $start + $break;
                }
            }

            $chunk = trim(mb_substr($text, $start, $end - $start));
            if ($chunk !== '') {
                $chunks[] = $chunk;
            }

            if ($end >= $length) {
                break;
            }

            $start = $this->overlapStart($text, max($end - $this->overlap, $start + 1), $end);
        }

        return $chunks;
    }

    /**
     * Begin the next chunk at a sentence start inside the overlap, or failing that a word start,
     * never mid-word.
     */
    private function overlapStart(string $text, int $from, int $end): int
    {
        $overlap = mb_substr($text, $from, $end - $from);

        foreach (['. ', '? ', '! ', "\n", ' '] as $boundary) {
            $position = mb_strpos($overlap, $boundary);
            if ($position !== false) {
                return $from + $position + mb_strlen($boundary);
            }
        }

        return $end;
    }

    private function lastBoundary(string $window): int
    {
        foreach (["\n\n", '. ', '? ', '! ', "\n", ' '] as $boundary) {
            $position = mb_strrpos($window, $boundary);
            if ($position !== false) {
                return $position + mb_strlen($boundary);
            }
        }

        return mb_strlen($window);
    }
}
