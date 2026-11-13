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
     * Reads an uploaded PDF and returns [contents, pages, page sizes in points]. FPDI reads most PDFs as
     * uploaded, including compressed ones; a PDF it cannot read (damaged, encrypted, unusual structure) is
     * repaired with qpdf and read again.
     *
     * @return array{0: string, 1: int, 2: list<array{0: float, 1: float}>}
     */
    public static function normalise(string $contents): array
    {
        if (! str_starts_with($contents, '%PDF-')) {
            throw new RuntimeException('That file is not a PDF.');
        }
        try {
            return [$contents, ...self::measure($contents)];
        } catch (\Throwable $original) {
            $repaired = self::viaQpdf($contents);
            if ($repaired !== null) {
                try {
                    return [$repaired, ...self::measure($repaired)];
                } catch (\Throwable $e) {
                    report($e);
                }
            }
            report($original);

            throw new RuntimeException('This PDF could not be read. Try printing it to a new PDF and upload again.');
        }
    }

    /** qpdf's rewrite, or null (tool missing or failed; logged for operations). */
    private static function viaQpdf(string $contents): ?string
    {
        $in = tempnam(sys_get_temp_dir(), 'form-in-');
        $out = $in.'.out.pdf';
        file_put_contents($in, $contents);
        try {
            $process = new Process([(string) config('forms.qpdf', 'qpdf'), '--object-streams=disable', '--decrypt', $in, $out]);
            $process->run();
            // qpdf exits 3 for warnings but still writes a usable file.
            if (in_array($process->getExitCode(), [0, 3], true) && is_file($out) && filesize($out) > 0) {
                return (string) file_get_contents($out);
            }
            logger()->warning('qpdf could not normalise a PDF', ['exit' => $process->getExitCode(), 'error' => mb_substr($process->getErrorOutput(), 0, 500)]);
        } catch (\Throwable $e) {
            logger()->warning('qpdf is not available', ['error' => $e->getMessage()]);
        } finally {
            @unlink($in);
            @unlink($out);
        }

        return null;
    }

    /**
     * @return array{0: int, 1: list<array{0: float, 1: float}>}
     */
    private static function measure(string $pdfContents): array
    {
        $file = tempnam(sys_get_temp_dir(), 'form-m-');
        file_put_contents($file, $pdfContents);
        try {
            $pdf = new Fpdi('P', 'pt'); // positions and sizes are in points
            $pages = $pdf->setSourceFile($file);
            $sizes = [];
            for ($i = 1; $i <= $pages; $i++) {
                $size = $pdf->getTemplateSize($pdf->importPage($i));
                if (! is_array($size)) {
                    throw new RuntimeException('Unreadable page.');
                }
                $sizes[] = [round((float) $size['width'], 2), round((float) $size['height'], 2)];
            }

            return [$pages, $sizes];
        } finally {
            @unlink($file);
        }
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
