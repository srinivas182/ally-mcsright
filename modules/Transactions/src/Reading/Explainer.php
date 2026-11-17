<?php

declare(strict_types=1);

namespace Modules\Transactions\Reading;

use App\Models\User;
use Illuminate\Validation\ValidationException;
use Modules\AI\AiGateway;
use Modules\AI\Data\AiTask;
use Modules\Transactions\Models\Document;
use Modules\Transactions\Models\DocumentReading;

/**
 * "Explain this document" in plain English or Spanish (specs/ai/07, FB1), for the agent and for a client the agent
 * chooses to share it with. Written by AI from the document's pages only, then checked in code:
 *  - every point must quote words that are really on the page it names, or it is dropped;
 *  - every amount in a point must be written somewhere in the document, or the point is dropped;
 *  - the "not legal advice" line is added in code, never left to the model;
 *  - when pages were scanned (or handwritten), a line says to check the original, because handwriting is not
 *    always read correctly.
 */
final class Explainer
{
    public const FEATURE = 'docs.explain';

    public const LANGS = ['en', 'es'];

    public const DISCLAIMER = [
        'en' => 'This explanation helps you read the document. It is not legal advice and does not change it: the document itself is what counts. Ask your agent or an attorney about anything you are unsure of.',
        'es' => 'Esta explicación le ayuda a leer el documento. No es asesoría legal y no lo modifica: lo que cuenta es el documento. Pregunte a su agente o a un abogado cualquier duda.',
    ];

    public const SCANNED = [
        'en' => 'Some pages were scanned or handwritten. Handwriting is not always read correctly, so check those pages against the original.',
        'es' => 'Algunas páginas fueron escaneadas o escritas a mano. La escritura a mano no siempre se lee bien; compare esas páginas con el original.',
    ];

    private const SCHEMA = ['type' => 'object', 'required' => ['headline', 'points'], 'properties' => [
        'headline' => ['type' => 'string'],
        'points' => ['type' => 'array', 'items' => ['type' => 'object', 'required' => ['text', 'page', 'quote'],
            'properties' => ['text' => ['type' => 'string'], 'page' => ['type' => 'integer'], 'quote' => ['type' => 'string'], 'kind' => ['type' => 'string']]]],
    ]];

    public function __construct(private readonly AiGateway $gateway) {}

    /**
     * @return array{lang: string, headline: string, points: list<array{text: string, page: int, quote: string, kind: string}>, removed: int, scanned: string|null, disclaimer: string}
     */
    public function explain(Document $doc, string $lang, User $by): array
    {
        $lang = in_array($lang, self::LANGS, true) ? $lang : 'en';
        $reading = DocumentReading::query()->where('document_id', $doc->id)->where('status', 'read')->first();
        $pages = $reading !== null ? array_map('strval', (array) $reading->page_text) : [];
        if ($pages === [] || trim(implode('', $pages)) === '') {
            throw ValidationException::withMessages(['explain' => 'This document has not been read yet, or has no readable text.']);
        }
        $out = [];
        foreach (array_slice($pages, 0, 40) as $i => $p) {
            $out[] = '[Page '.($i + 1)."]\n".mb_substr($p, 0, 6000);
        }
        $result = $this->gateway->run(new AiTask(self::FEATURE, 'docs.explain_document', 'docs-explain:'.$doc->ulid.':'.$lang.':'.(string) $doc->sha256,
            inputs: ['language' => $lang === 'es' ? 'Spanish (neutral, for US home buyers and sellers)' : 'plain English'], untrustedContent: $out,
            outputSchema: self::SCHEMA, userId: $by->id));

        return self::check((array) $result->json, $pages, $lang, in_array('ocr', (array) $reading->methods, true));
    }

    /**
     * Keeps only points whose quote is on its page and whose amounts are in the document.
     *
     * @param  array<string, mixed>  $json
     * @param  list<string>  $pages
     * @return array{lang: string, headline: string, points: list<array{text: string, page: int, quote: string, kind: string}>, removed: int, scanned: string|null, disclaimer: string}
     */
    public static function check(array $json, array $pages, string $lang, bool $scanned): array
    {
        $inDoc = self::numbers(implode(' ', $pages));
        $points = [];
        $removed = 0;
        foreach (array_slice((array) ($json['points'] ?? []), 0, 12) as $p) {
            $text = trim((string) ($p['text'] ?? ''));
            $page = (int) ($p['page'] ?? 0);
            $quote = trim((string) ($p['quote'] ?? ''));
            if ($text === '' || ! DocIq::onPage($quote, $pages[$page - 1] ?? '') || array_diff(self::numbers($text), $inDoc) !== []) {
                $removed++;

                continue;
            }
            $kind = (string) ($p['kind'] ?? '');
            $points[] = ['text' => mb_substr($text, 0, 400), 'page' => $page, 'quote' => mb_substr($quote, 0, 200),
                'kind' => in_array($kind, ['money', 'date', 'duty', 'risk', 'other'], true) ? $kind : 'other'];
        }
        $headline = trim((string) ($json['headline'] ?? ''));
        if (array_diff(self::numbers($headline), $inDoc) !== []) {
            $headline = '';
        }

        return ['lang' => $lang, 'headline' => mb_substr($headline, 0, 300), 'points' => $points, 'removed' => $removed,
            'scanned' => $scanned ? self::SCANNED[$lang] : null, 'disclaimer' => self::DISCLAIMER[$lang]];
    }

    /**
     * Figures of two or more digits, without separators or trailing ".00" ("$485,000.00" → "485000").
     *
     * @return list<string>
     */
    private static function numbers(string $text): array
    {
        preg_match_all('/\d[\d,.]*\d/', $text, $m);
        $out = [];
        foreach ($m[0] as $n) {
            $digits = ltrim((string) preg_replace('/\D/', '', (string) preg_replace('/[.,]00$/', '', $n)), '0');
            if (strlen($digits) >= 2) {
                $out[] = $digits;
            }
        }

        return array_values(array_unique($out));
    }
}
