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

    /**
     * Writes PDF bytes to a new temporary file ending in ".pdf". External tools get only such names: on hardened
     * hosts (Ubuntu's AppArmor profile for qpdf, for example) they may refuse to open files without the extension.
     */
    public static function tempFile(string $prefix, string $contents): string
    {
        $base = tempnam(sys_get_temp_dir(), $prefix);
        if ($base === false) {
            throw new RuntimeException('No temporary space for the PDF.');
        }
        $path = $base.'.pdf';
        rename($base, $path);
        file_put_contents($path, $contents);

        return $path;
    }

    /** qpdf's rewrite, or null (tool missing or failed; logged for operations). */
    private static function viaQpdf(string $contents): ?string
    {
        $in = self::tempFile('form-in-', $contents);
        $out = substr($in, 0, -4).'.out.pdf';
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

    /**
     * One document from several forms: each form's pages with its stamps (text, or a PNG signature image),
     * followed by an audit certificate page.
     *
     * @param  list<array{pdf: string, stamps: list<array{page: int, x: float, y: float, w: float, h: float, text?: string, style?: string, image?: string}>}>  $forms
     * @param  list<string>  $certificate  lines of the audit certificate
     */
    public static function assemble(array $forms, array $certificate): string
    {
        $doc = new Fpdi('P', 'pt');
        $doc->SetAutoPageBreak(false);
        $tmp = [];
        foreach ($forms as $form) {
            $src = tempnam(sys_get_temp_dir(), 'form-a-');
            file_put_contents($src, $form['pdf']);
            $tmp[] = $src;
            $pages = $doc->setSourceFile($src);
            for ($i = 1; $i <= $pages; $i++) {
                $tpl = $doc->importPage($i);
                $size = $doc->getTemplateSize($tpl);
                if (! is_array($size)) {
                    throw new RuntimeException('This PDF could not be read.');
                }
                $doc->AddPage($size['orientation'], [$size['width'], $size['height']]);
                $doc->useTemplate($tpl);
                foreach ($form['stamps'] as $s) {
                    if ($s['page'] !== $i) {
                        continue;
                    }
                    if (isset($s['image']) && $s['image'] !== '') {
                        $img = tempnam(sys_get_temp_dir(), 'sig-').'.png';
                        file_put_contents($img, $s['image']);
                        $tmp[] = $img;
                        $doc->Image($img, $s['x'], $s['y'], $s['w'], $s['h'], 'PNG');

                        continue;
                    }
                    if (($s['text'] ?? '') === '') {
                        continue;
                    }
                    $doc->SetFont('Helvetica', ($s['style'] ?? '') === 'signature' ? 'I' : '', min(11, max(6, $s['h'] * 0.7)));
                    $doc->SetTextColor(15, 23, 80);
                    $doc->SetXY($s['x'], $s['y']);
                    $doc->Cell($s['w'], $s['h'], self::latin1((string) $s['text']), 0, 0, 'L');
                }
            }
        }
        $doc->AddPage('P', [612, 792]);
        $doc->SetTextColor(20, 20, 20);
        $doc->SetFont('Helvetica', 'B', 14);
        $doc->SetXY(54, 54);
        $doc->Cell(500, 18, 'Signature certificate', 0, 1);
        $doc->SetFont('Helvetica', '', 9);
        $doc->SetX(54);
        foreach ($certificate as $line) {
            $doc->SetX(54);
            $doc->MultiCell(504, 12, self::latin1($line), 0, 'L');
        }
        $out = $doc->Output('S');
        foreach ($tmp as $f) {
            @unlink($f);
        }

        return $out;
    }

    private static function latin1(string $text): string
    {
        return (string) iconv('UTF-8', 'windows-1252//TRANSLIT', $text);
    }
}
