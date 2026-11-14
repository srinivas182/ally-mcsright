<?php

declare(strict_types=1);

namespace Modules\Forms\Library;

use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;

/**
 * Reads the fillable fields (AcroForm widgets) of a PDF: name, type, page and rectangle. Exports from dotloop,
 * SkySlope, zipForm and most association forms are fillable PDFs, so their fields can be placed automatically.
 *
 * Built from qpdf's raw object dump (JSON v2 "qpdf" and "pages" keys), whose format is the same across qpdf 11
 * and 12, rather than its higher-level "acroform" summary, which differs between versions. Returns [] when the
 * PDF has no fillable fields or qpdf is unavailable (the form still imports, to be mapped in the visual editor).
 */
final class FillableFields
{
    /** Why the last read found nothing to read (for logs and support), or null. */
    public static ?string $lastError = null;

    /**
     * @return list<array{name: string, type: string, page: int, rect: array{0: float, 1: float, 2: float, 3: float}}>
     */
    public static function read(string $pdf): array
    {
        self::$lastError = null;
        $json = self::dump($pdf);
        if ($json === null) {
            Log::warning('Forms import: could not read fillable fields', ['reason' => self::$lastError]);

            return [];
        }
        /** @var array<string, mixed> $objects */
        $objects = (array) ($json['qpdf'][1] ?? []);
        $value = fn (?string $ref): array => $ref !== null ? (array) ($objects['obj:'.$ref]['value'] ?? []) : [];

        // Page number of each page object, and which page each annotation sits on.
        $pageOf = [];
        $annotPage = [];
        foreach (array_values((array) ($json['pages'] ?? [])) as $i => $page) {
            $ref = (string) ($page['object'] ?? '');
            $pageOf[$ref] = $i + 1;
            $annots = $value($ref)['/Annots'] ?? [];
            if (is_string($annots)) {
                $annots = $value($annots);
            }
            foreach ((array) $annots as $a) {
                if (is_string($a)) {
                    $annotPage[$a] = $i + 1;
                }
            }
        }

        $out = [];
        foreach ($objects as $key => $object) {
            $o = (array) ($object['value'] ?? []);
            if (! str_starts_with($key, 'obj:') || ($o['/Subtype'] ?? null) !== '/Widget' || ! is_array($o['/Rect'] ?? null) || count($o['/Rect']) !== 4) {
                continue;
            }
            $ref = substr($key, 4);
            // Name and type may be inherited from parent fields ("Seller.Signature").
            $names = [];
            $type = null;
            $flags = 0;
            $node = $o;
            for ($depth = 0; $depth < 10 && $node !== []; $depth++) {
                if (isset($node['/T'])) {
                    array_unshift($names, self::text($node['/T']));
                }
                $type ??= isset($node['/FT']) ? (string) $node['/FT'] : null;
                $flags = $flags !== 0 ? $flags : (int) ($node['/Ff'] ?? 0);
                $node = is_string($node['/Parent'] ?? null) ? $value($node['/Parent']) : [];
            }
            $name = trim(implode('.', array_filter($names, fn (string $n): bool => $n !== '')));
            $kind = match ($type) {
                '/Sig' => 'signature',
                '/Tx', '/Ch' => 'text',
                '/Btn' => ($flags & (1 << 15)) !== 0 || ($flags & (1 << 16)) !== 0 ? null : 'checkbox', // radio or push button: skip
                default => null,
            };
            $page = is_string($o['/P'] ?? null) ? ($pageOf[$o['/P']] ?? null) : null;
            $page ??= $annotPage[$ref] ?? null;
            if ($kind === null || $name === '' || $page === null) {
                continue;
            }
            $r = array_map(fn ($n): float => (float) $n, array_values($o['/Rect']));
            $out[] = ['name' => $name, 'type' => $kind, 'page' => $page, 'rect' => [min($r[0], $r[2]), min($r[1], $r[3]), max($r[0], $r[2]), max($r[1], $r[3])]];
        }
        usort($out, fn (array $a, array $b): int => [$a['page'], -$a['rect'][3], $a['rect'][0]] <=> [$b['page'], -$b['rect'][3], $b['rect'][0]]);

        return $out;
    }

    /** A PDF string from qpdf JSON v2: "u:" text, "b:" hex bytes, or a plain value. */
    private static function text(mixed $value): string
    {
        $s = (string) (is_scalar($value) ? $value : '');
        if (str_starts_with($s, 'u:')) {
            return substr($s, 2);
        }
        if (str_starts_with($s, 'b:')) {
            $bytes = (string) hex2bin(substr($s, 2));
            if (str_starts_with($bytes, "\xFE\xFF")) {
                return (string) mb_convert_encoding(substr($bytes, 2), 'UTF-8', 'UTF-16BE');
            }

            return (string) mb_convert_encoding($bytes, 'UTF-8', 'ISO-8859-1');
        }

        return $s;
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function dump(string $pdf): ?array
    {
        $file = tempnam(sys_get_temp_dir(), 'acro');
        if ($file === false) {
            return null;
        }
        try {
            file_put_contents($file, $pdf);
            $process = new Process([(string) config('forms.qpdf', 'qpdf'), '--json=2', '--json-key=pages', '--json-key=qpdf', $file]);
            $process->setTimeout(30);
            $process->run();
            // qpdf exits 3 for warnings with usable output.
            if (! in_array($process->getExitCode(), [0, 3], true)) {
                self::$lastError = 'qpdf exit '.$process->getExitCode().': '.mb_substr(trim($process->getErrorOutput()), 0, 500);

                return null;
            }
            $json = json_decode($process->getOutput(), true);
            if (! is_array($json)) {
                self::$lastError = 'qpdf output was not JSON: '.mb_substr($process->getOutput(), 0, 200).' / '.mb_substr(trim($process->getErrorOutput()), 0, 300);
            }

            return is_array($json) ? $json : null;
        } catch (\Throwable $e) {
            self::$lastError = $e::class.': '.$e->getMessage();

            return null;
        } finally {
            @unlink($file);
        }
    }
}
