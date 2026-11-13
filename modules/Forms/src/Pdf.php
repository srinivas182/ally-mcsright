<?php

declare(strict_types=1);

namespace Modules\Forms;

use RuntimeException;
use setasign\Fpdi\Fpdi;
use Symfony\Component\Process\Process;

/**
 * PDF handling for forms: uploaded PDFs are normalised with qpdf (no object streams, so FPDI can import any
 * form), then values are stamped onto the imported pages. Positions are in points from the page's top-left.
 */
final class Pdf
{
    /**
     * Normalises a PDF and returns [contents, pages, page sizes in points].
     *
     * @return array{0: string, 1: int, 2: list<array{0: float, 1: float}>}
     */
    public static function normalise(string $contents): array
    {
        if (! str_starts_with($contents, '%PDF-')) {
            throw new RuntimeException('That file is not a PDF.');
        }
        $in = tempnam(sys_get_temp_dir(), 'form-in-');
        $out = tempnam(sys_get_temp_dir(), 'form-out-');
        file_put_contents($in, $contents);
        $process = new Process(['qpdf', '--object-streams=disable', '--decrypt', $in, $out]);
        $process->run();
        // qpdf exits 3 for warnings but still writes a usable file.
        if (! in_array($process->getExitCode(), [0, 3], true) || ! is_file($out) || filesize($out) === 0) {
            @unlink($in);
            @unlink($out);
            throw new RuntimeException('This PDF could not be read. Try printing it to a new PDF and upload again.');
        }
        $normalised = (string) file_get_contents($out);
        $pdf = new Fpdi('P', 'pt'); // positions and sizes are in points
        $pages = $pdf->setSourceFile($out);
        $sizes = [];
        for ($i = 1; $i <= $pages; $i++) {
            $size = $pdf->getTemplateSize($pdf->importPage($i));
            if (! is_array($size)) {
                throw new RuntimeException('This PDF could not be read.');
            }
            $sizes[] = [round((float) $size['width'], 2), round((float) $size['height'], 2)];
        }
        @unlink($in);
        @unlink($out);

        return [$normalised, $pages, $sizes];
    }

    /**
     * Stamps values onto a normalised PDF.
     *
     * @param  list<array{page: int, x: float, y: float, w: float, h: float, text: string, style?: string}>  $stamps
     */
    public static function fill(string $normalised, array $stamps): string
    {
        $src = tempnam(sys_get_temp_dir(), 'form-src-');
        file_put_contents($src, $normalised);
        $pdf = new Fpdi('P', 'pt');
        $pdf->SetAutoPageBreak(false);
        $pages = $pdf->setSourceFile($src);
        for ($i = 1; $i <= $pages; $i++) {
            $tpl = $pdf->importPage($i);
            $size = $pdf->getTemplateSize($tpl);
            if (! is_array($size)) {
                throw new RuntimeException('This PDF could not be read.');
            }
            $pdf->AddPage($size['orientation'], [$size['width'], $size['height']]);
            $pdf->useTemplate($tpl);
            foreach ($stamps as $s) {
                if ($s['page'] !== $i || $s['text'] === '') {
                    continue;
                }
                $pdf->SetFont('Helvetica', ($s['style'] ?? '') === 'signature' ? 'I' : '', min(11, max(6, $s['h'] * 0.7)));
                $pdf->SetTextColor(15, 23, 80);
                $pdf->SetXY($s['x'], $s['y']);
                $pdf->Cell($s['w'], $s['h'], self::latin1($s['text']), 0, 0, 'L');
            }
        }
        @unlink($src);

        return $pdf->Output('S');
    }

    private static function latin1(string $text): string
    {
        return (string) iconv('UTF-8', 'windows-1252//TRANSLIT', $text);
    }
}
