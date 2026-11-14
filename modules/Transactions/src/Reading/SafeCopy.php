<?php

declare(strict_types=1);

namespace Modules\Transactions\Reading;

use FPDF;
use Modules\Forms\Pdf;
use setasign\Fpdi\Fpdi;
use Symfony\Component\Process\Process;

/**
 * A copy of a document with Social Security, bank account and routing numbers and dates of birth blacked out,
 * for sharing with other parties. Word positions come from the PDF's own text, or from OCR for scans and
 * photos. Every page with something to hide is flattened to an image before the black boxes are drawn, so the
 * hidden numbers cannot be copied out from underneath; pages with nothing to hide are kept as they are.
 */
final class SafeCopy
{
    private const DPI = 150;

    /**
     * @param  list<string>  $methods  per page: text, ocr or blank (from the reading)
     */
    public static function make(string $bytes, string $mime, array $methods): string
    {
        if ($mime !== 'application/pdf') {
            return self::image($bytes, $mime === 'image/png' ? 'png' : 'jpg');
        }
        $pdf = Pdf::normalise($bytes)[0];
        $file = Pdf::tempFile('safe-', $pdf);
        $tmp = [];
        try {
            $out = new Fpdi('P', 'pt');
            $out->SetAutoPageBreak(false);
            $count = $out->setSourceFile($file);
            for ($n = 1; $n <= $count; $n++) {
                $tpl = $out->importPage($n);
                $size = $out->getTemplateSize($tpl);
                if (! is_array($size)) {
                    continue;
                }
                [$w, $h] = [(float) $size['width'], (float) $size['height']];
                $boxes = ($methods[$n - 1] ?? 'text') === 'text' ? self::textBoxes($file, $n) : [];
                $png = null;
                if ($boxes === [] && ($methods[$n - 1] ?? 'text') !== 'text') {
                    $png = self::render($file, $n);
                    if ($png !== null) {
                        $tmp[] = $png;
                        $boxes = self::ocrBoxes($png, $w);
                    }
                }
                $out->AddPage($w > $h ? 'L' : 'P', [$w, $h]);
                if ($boxes === []) {
                    $out->useTemplate($tpl);

                    continue;
                }
                // Something to hide: place a flattened image of the page, so no text survives under the boxes.
                $png ??= self::render($file, $n);
                if ($png === null) {
                    $out->useTemplate($tpl);
                } else {
                    $tmp[] = $png;
                    $out->Image($png, 0, 0, $w, $h);
                }
                $out->SetFillColor(0, 0, 0);
                foreach ($boxes as [$x, $y, $bw, $bh]) {
                    $out->Rect($x - 1, $y - 1, $bw + 2, $bh + 2, 'F');
                }
            }

            return $out->Output('S');
        } finally {
            @unlink($file);
            foreach (array_unique($tmp) as $t) {
                @unlink($t);
            }
        }
    }

    /**
     * Boxes (x, y, w, h in points) of the sensitive words on one text page.
     *
     * @return list<array{0: float, 1: float, 2: float, 3: float}>
     */
    private static function textBoxes(string $file, int $page): array
    {
        $words = self::textWords($file, $page);

        return array_map(fn (int $i): array => $words[$i][1], Sensitive::wordsToHide(array_column($words, 0)));
    }

    /**
     * Words on a text page with their boxes (x, y, w, h in points), in reading order.
     *
     * @return list<array{0: string, 1: array{0: float, 1: float, 2: float, 3: float}}>
     */
    public static function textWords(string $file, int $page): array
    {
        $p = new Process(['pdftotext', '-bbox', '-f', (string) $page, '-l', (string) $page, $file, '-']);
        $p->setTimeout(30);
        $p->run();
        preg_match_all('/<word xMin="([\d.]+)" yMin="([\d.]+)" xMax="([\d.]+)" yMax="([\d.]+)">(.*?)<\/word>/s', $p->getOutput(), $m, PREG_SET_ORDER);

        return array_map(fn (array $w): array => [html_entity_decode($w[5], ENT_QUOTES | ENT_XML1),
            [(float) $w[1], (float) $w[2], (float) $w[3] - (float) $w[1], (float) $w[4] - (float) $w[2]]], $m);
    }

    /**
     * Boxes of the sensitive words found by OCR on a rendered page image, scaled to points.
     *
     * @return list<array{0: float, 1: float, 2: float, 3: float}>
     */
    private static function ocrBoxes(string $png, float $pageWidth): array
    {
        $words = self::ocrWords($png, $pageWidth);

        return array_map(fn (int $i): array => $words[$i][1], Sensitive::wordsToHide(array_column($words, 0)));
    }

    /**
     * Words OCR finds on a page image, with boxes scaled to points.
     *
     * @return list<array{0: string, 1: array{0: float, 1: float, 2: float, 3: float}}>
     */
    public static function ocrWords(string $png, float $pageWidth): array
    {
        $p = new Process(['tesseract', $png, 'stdout', '-l', 'eng', 'tsv']);
        $p->setTimeout(90);
        $p->run();
        $info = @getimagesize($png);
        $scale = is_array($info) && $info[0] > 0 ? $pageWidth / $info[0] : 72 / self::DPI;
        $out = [];
        foreach (explode("\n", $p->getOutput()) as $line) {
            $c = explode("\t", $line);
            if (count($c) === 12 && $c[0] === '5' && trim($c[11]) !== '') {
                $out[] = [trim($c[11]), [(float) $c[6] * $scale, (float) $c[7] * $scale, (float) $c[8] * $scale, (float) $c[9] * $scale]];
            }
        }

        return $out;
    }

    public static function render(string $file, int $page): ?string
    {
        $base = substr($file, 0, -4).'-s'.$page;
        $p = new Process(['pdftoppm', '-r', (string) self::DPI, '-png', '-f', (string) $page, '-l', (string) $page, '-singlefile', $file, $base]);
        $p->setTimeout(60);
        $p->run();

        return is_file($base.'.png') ? $base.'.png' : null;
    }

    private static function image(string $bytes, string $extension): string
    {
        $file = tempnam(sys_get_temp_dir(), 'safe-img-');
        if ($file === false) {
            throw new \RuntimeException('No temporary space.');
        }
        $path = $file.'.'.$extension;
        rename($file, $path);
        file_put_contents($path, $bytes);
        try {
            $info = getimagesize($path);
            if (! is_array($info)) {
                throw new \RuntimeException('Unreadable image.');
            }
            // Fit the photo on a page in points (1 px = 0.5 pt keeps phone photos a sensible size).
            [$w, $h] = [$info[0] * 0.5, $info[1] * 0.5];
            $pdf = new FPDF($w > $h ? 'L' : 'P', 'pt', [$w, $h]);
            $pdf->SetAutoPageBreak(false);
            $pdf->AddPage();
            $pdf->Image($path, 0, 0, $w, $h);
            $pdf->SetFillColor(0, 0, 0);
            foreach (self::ocrBoxes($path, $w) as [$x, $y, $bw, $bh]) {
                $pdf->Rect($x - 1, $y - 1, $bw + 2, $bh + 2, 'F');
            }

            return $pdf->Output('S');
        } finally {
            @unlink($path);
        }
    }
}
