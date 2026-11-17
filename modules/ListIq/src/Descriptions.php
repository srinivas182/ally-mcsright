<?php

declare(strict_types=1);

namespace Modules\ListIq;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use Modules\AI\AiGateway;
use Modules\AI\Data\AiResult;
use Modules\AI\Data\AiTask;
use Modules\AI\Exceptions\InsufficientCredits;
use Modules\Crm\FairHousing;
use Modules\ListIq\Models\Prep;
use Throwable;

/**
 * Listing descriptions (LI1, specs/listings/02): three AI drafts (warm, crisp, luxury) from the facts and the agent's
 * own notes, each within the MLS's character limit and checked against the Fair Housing word list; the agent picks
 * and edits one. A Spanish version on request.
 */
final class Descriptions
{
    public const FEATURE = 'listiq.description';

    public const STYLES = ['warm' => 'Warm', 'crisp' => 'Crisp', 'luxury' => 'Luxury'];

    public function write(Prep $prep, User $by): void
    {
        $f = (array) $prep->facts;
        if (trim((string) $prep->notes) === '' && trim((string) ($f['features'] ?? '')) === '') {
            throw ValidationException::withMessages(['notes' => 'Add a few notes about the home first: what\'s special, what\'s been updated.']);
        }
        $facts = trim(implode(', ', array_filter([isset($f['beds']) ? $f['beds'].' bedrooms' : null, isset($f['baths']) ? $f['baths'].' bathrooms' : null,
            isset($f['sqft']) ? number_format((int) $f['sqft']).' sq ft' : null, isset($f['year_built']) ? 'built '.$f['year_built'] : null, $prep->city])));
        $result = $this->run(new AiTask(self::FEATURE, 'listiq.description', 'listiq-desc:'.$prep->ulid.':'.CarbonImmutable::now()->format('YmdHisv'),
            inputs: ['limit' => (string) $prep->remarks_limit, 'facts' => $facts !== '' ? $facts : 'not given'],
            untrustedContent: array_values(array_filter(['Features: '.($f['features'] ?? ''), 'Agent notes: '.(string) $prep->notes], fn (string $s): bool => ! str_ends_with($s, ': '))),
            outputSchema: ['type' => 'object', 'required' => array_keys(self::STYLES), 'properties' => array_map(fn (): array => ['type' => 'string'], self::STYLES)], userId: $by->id));
        $out = [];
        foreach (array_keys(self::STYLES) as $style) {
            $text = self::clean((string) ($result->json[$style] ?? ''), $prep->remarks_limit);
            if ($text !== '') {
                $out[] = ['style' => $style, 'text' => $text];
            }
        }
        if ($out === []) {
            throw ValidationException::withMessages(['notes' => 'The writing helper didn\'t manage this one. Please try again.']);
        }
        $prep->descriptions = $out;
        $prep->save();
    }

    public function translate(Prep $prep, User $by): void
    {
        if ($prep->description === null) {
            throw ValidationException::withMessages(['description' => 'Choose the English description first.']);
        }
        $result = $this->run(new AiTask(self::FEATURE, 'listiq.translate', 'listiq-es:'.$prep->ulid.':'.CarbonImmutable::now()->format('YmdHisv'),
            inputs: ['limit' => (string) $prep->remarks_limit], untrustedContent: [$prep->description],
            outputSchema: ['type' => 'object', 'required' => ['text'], 'properties' => ['text' => ['type' => 'string']]], userId: $by->id));
        $prep->description_es = self::clean((string) ($result->json['text'] ?? ''), $prep->remarks_limit) ?: null;
        $prep->save();
    }

    /**
     * The Fair Housing words in a description, with plainer wording to use instead.
     *
     * @return list<array{phrase: string, instead: string}>
     */
    public static function check(string $text): array
    {
        $instead = ['master' => 'primary', 'family-friendly' => 'describe the home: "large yard", "open floor plan"', 'family friendly' => 'describe the home itself',
            'safe' => 'describe features, not the area\'s safety', 'perfect for' => 'say what the home has, not who it suits'];

        return array_map(function (string $flag) use ($instead): array {
            foreach ($instead as $k => $v) {
                if (str_contains($flag, $k)) {
                    return ['phrase' => $flag, 'instead' => $v];
                }
            }

            return ['phrase' => $flag, 'instead' => 'describe the property, not who should live there'];
        }, FairHousing::flags($text));
    }

    private function run(AiTask $task): AiResult
    {
        try {
            return app(AiGateway::class)->run($task);
        } catch (ValidationException|InsufficientCredits $e) {
            throw $e instanceof InsufficientCredits ? ValidationException::withMessages(['notes' => $e->getMessage()]) : $e;
        } catch (Throwable $e) {
            report($e);
            throw ValidationException::withMessages(['notes' => 'The writing helper didn\'t manage this one. Please try again, or write it yourself.']);
        }
    }

    /** No links, emails, phone numbers or shouting; cut at a sentence within the limit. */
    private static function clean(string $text, int $limit): string
    {
        $text = (string) preg_replace(['~https?://\S+~i', '/\S+@\S+\.\S+/', '/\(?\d{3}\)?[\s.-]?\d{3}[\s.-]?\d{4}/'], '', $text);
        $text = trim((string) preg_replace('/\s+/', ' ', $text));
        if (mb_strlen($text) <= $limit) {
            return $text;
        }
        $cut = mb_substr($text, 0, $limit);
        $end = max(mb_strrpos($cut, '. ') ?: 0, mb_strrpos($cut, '! ') ?: 0);

        return $end > $limit / 2 ? mb_substr($cut, 0, $end + 1) : rtrim(mb_substr($cut, 0, (int) mb_strrpos($cut, ' ')), ',;').'…';
    }
}
