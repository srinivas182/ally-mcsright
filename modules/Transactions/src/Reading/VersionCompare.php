<?php

declare(strict_types=1);

namespace Modules\Transactions\Reading;

use Modules\Transactions\Models\Document;
use Modules\Transactions\Models\DocumentReading;

/**
 * What changed between two versions of a document (specs/ai/07, FB1). When a counter offer or a re-signed
 * contract replaces the copy in a checklist slot, the agent and the broker see, in plain words:
 *  - the key facts that changed (price 450,000 → 445,000; closing date…), from each version's DocIQ reading;
 *  - the lines added and the lines taken out, from the pages' text.
 * Done in code, not AI, so it is free, instant and never imagines a change. It compares what was read; for a
 * poor scan it says the text may be incomplete.
 */
final class VersionCompare
{
    private const MAX_LINES = 40;

    /** The version this one replaced: the newest earlier upload in the same checklist slot (or with the same name). */
    public static function previous(Document $doc): ?Document
    {
        $q = Document::query()->where('deal_id', $doc->deal_id)->where('id', '<', $doc->id)->whereNull('split_into');
        $q = $doc->item_id !== null ? $q->where('item_id', $doc->item_id) : $q->where('filename', $doc->filename);

        return $q->orderByDesc('id')->first();
    }

    /**
     * @return array{ready: bool, message: string|null, before: array{filename: string, version: int, uploaded: string|null}|null,
     *     facts: list<array{label: string, before: string, after: string}>, added: list<string>, removed: list<string>, more: int, scanned: bool}
     */
    public static function compare(Document $doc, ?Document $before = null): array
    {
        $before ??= self::previous($doc);
        $empty = ['ready' => false, 'before' => null, 'facts' => [], 'added' => [], 'removed' => [], 'more' => 0, 'scanned' => false];
        if ($before === null) {
            return ['message' => 'This is the first version of this document, so there is nothing to compare yet.'] + $empty;
        }
        $old = DocumentReading::query()->where('document_id', $before->id)->where('status', 'read')->first();
        $new = DocumentReading::query()->where('document_id', $doc->id)->where('status', 'read')->first();
        $meta = ['filename' => (string) $before->filename, 'version' => (int) $before->version, 'uploaded' => $before->getAttribute('created_at')?->toDateString()];
        if ($old === null || $new === null) {
            return ['message' => 'One of the two versions has not been read yet. Try again in a minute.', 'before' => $meta] + $empty;
        }

        $facts = [];
        $oldFacts = array_column((array) $old->facts, 'value', 'name');
        foreach ((array) $new->facts as $f) {
            $was = (string) ($oldFacts[$f['name']] ?? '');
            if (self::norm($was) !== self::norm((string) $f['value'])) {
                $facts[] = ['label' => DocIq::FACT_LABELS[$f['name']] ?? (string) $f['name'], 'before' => $was !== '' ? $was : '(not stated)', 'after' => (string) $f['value']];
            }
            unset($oldFacts[$f['name']]);
        }
        foreach ($oldFacts as $name => $value) {
            $facts[] = ['label' => DocIq::FACT_LABELS[$name] ?? (string) $name, 'before' => (string) $value, 'after' => '(no longer stated)'];
        }

        [$added, $removed] = self::lines((array) $old->page_text, (array) $new->page_text);
        $more = max(0, count($added) - self::MAX_LINES) + max(0, count($removed) - self::MAX_LINES);
        $scanned = in_array('ocr', array_merge((array) $old->methods, (array) $new->methods), true);

        return ['ready' => true, 'message' => $facts === [] && $added === [] && $removed === [] ? 'No differences found in the text of the two versions.' : null,
            'before' => $meta, 'facts' => $facts, 'added' => array_slice($added, 0, self::MAX_LINES), 'removed' => array_slice($removed, 0, self::MAX_LINES),
            'more' => $more, 'scanned' => $scanned];
    }

    /**
     * Lines in the new text but not the old (added) and the other way round (removed), counting repeats.
     *
     * @param  array<int, mixed>  $oldPages
     * @param  array<int, mixed>  $newPages
     * @return array{0: list<string>, 1: list<string>}
     */
    public static function lines(array $oldPages, array $newPages): array
    {
        $split = function (array $pages): array {
            $out = [];
            foreach ($pages as $p) {
                foreach (preg_split('/\R/u', (string) $p) ?: [] as $l) {
                    $l = trim((string) preg_replace('/\s+/u', ' ', $l));
                    if (mb_strlen($l) >= 3 && preg_match('/^[\d\s.\-_|]+$/', $l) !== 1) { // skip page numbers and rules
                        $out[] = mb_substr($l, 0, 200);
                    }
                }
            }

            return $out;
        };
        $old = $split($oldPages);
        $new = $split($newPages);
        $count = array_count_values(array_map(self::norm(...), $old));
        $added = [];
        foreach ($new as $l) {
            $k = self::norm($l);
            if (($count[$k] ?? 0) > 0) {
                $count[$k]--;
            } else {
                $added[] = $l;
            }
        }
        $countNew = array_count_values(array_map(self::norm(...), $new));
        $removed = [];
        foreach ($old as $l) {
            $k = self::norm($l);
            if (($countNew[$k] ?? 0) > 0) {
                $countNew[$k]--;
            } else {
                $removed[] = $l;
            }
        }

        return [$added, $removed];
    }

    private static function norm(string $s): string
    {
        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $s)));
    }
}
