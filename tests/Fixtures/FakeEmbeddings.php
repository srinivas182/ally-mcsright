<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Modules\AI\Contracts\EmbeddingAdapter;
use Modules\AI\Data\EmbeddingResponse;
use Modules\AI\Models\AiProvider;
use Throwable;

/**
 * Deterministic embeddings for tests: a hashed bag of words in 64 dimensions, so texts sharing
 * words are similar. Records every text it receives.
 */
final class FakeEmbeddings implements EmbeddingAdapter
{
    /** @var list<string> */
    public array $received = [];

    public ?Throwable $failWith = null;

    /** @var int|null when set, returns vectors of this many dimensions for the first text only */
    public ?int $brokenDimensions = null;

    public function embed(AiProvider $provider, string $modelId, array $texts, int $timeoutSeconds): EmbeddingResponse
    {
        if ($this->failWith !== null) {
            throw $this->failWith;
        }

        array_push($this->received, ...$texts);
        $vectors = array_map(fn (string $text): array => self::vector($text), $texts);

        if ($this->brokenDimensions !== null) {
            $vectors[0] = array_fill(0, $this->brokenDimensions, 1.0);
        }

        return new EmbeddingResponse($vectors, array_sum(array_map(fn ($t) => str_word_count($t), $texts)));
    }

    /**
     * @return list<float>
     */
    public static function vector(string $text): array
    {
        $vector = array_fill(0, 64, 0.0);
        preg_match_all('/[a-z0-9]+/', strtolower($text), $words);

        foreach ($words[0] as $word) {
            if (strlen($word) > 2) {
                $vector[crc32($word) % 64] += 1.0;
            }
        }

        return array_values($vector);
    }
}
