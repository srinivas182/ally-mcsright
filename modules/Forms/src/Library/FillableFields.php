<?php

declare(strict_types=1);

namespace Modules\Forms\Library;

use Symfony\Component\Process\Process;

/**
 * Reads the fillable fields (AcroForm) of a PDF with qpdf: name, type, page and rectangle. Exports from dotloop,
 * SkySlope, zipForm and most association forms are fillable PDFs, so their fields can be placed automatically.
 * Returns [] when the PDF has no fillable fields or qpdf is unavailable (the form still imports, to be mapped in
 * the visual editor).
 */
final class FillableFields
{
    /**
     * @return list<array{name: string, type: string, page: int, rect: array{0: float, 1: float, 2: float, 3: float}}>
     */
    public static function read(string $pdf): array
    {
        $file = tempnam(sys_get_temp_dir(), 'acro');
        if ($file === false) {
            return [];
        }
        try {
            file_put_contents($file, $pdf);
            $process = new Process([(string) config('forms.qpdf', 'qpdf'), '--json', '--json-key=acroform', '--json-key=qpdf', $file]);
            $process->setTimeout(30);
            $process->run();
            // qpdf exits 3 for warnings with usable output.
            if (! in_array($process->getExitCode(), [0, 3], true)) {
                return [];
            }
            $json = json_decode($process->getOutput(), true);
        } catch (\Throwable) {
            return [];
        } finally {
            @unlink($file);
        }
        if (! is_array($json)) {
            return [];
        }
        $objects = (array) ($json['qpdf'][1] ?? []);
        $out = [];
        foreach ((array) ($json['acroform']['fields'] ?? []) as $f) {
            $ref = (string) ($f['annotation']['object'] ?? $f['object'] ?? '');
            $rect = $objects['obj:'.$ref]['value']['/Rect'] ?? null;
            if (! is_array($rect) || count($rect) !== 4) {
                continue;
            }
            $type = match (true) {
                ($f['fieldtype'] ?? '') === '/Sig' => 'signature',
                (bool) ($f['ischeckbox'] ?? false) => 'checkbox',
                (bool) ($f['istext'] ?? false) => 'text',
                default => '',
            };
            $name = trim((string) preg_replace('/^u:/', '', (string) ($f['fullname'] ?? '')));
            if ($type === '' || $name === '') {
                continue;
            }
            $r = array_map('floatval', array_values($rect));
            $out[] = ['name' => $name, 'type' => $type, 'page' => (int) ($f['pageposfrom1'] ?? 1),
                'rect' => [min($r[0], $r[2]), min($r[1], $r[3]), max($r[0], $r[2]), max($r[1], $r[3])]];
        }

        return $out;
    }
}
