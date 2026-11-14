<?php

declare(strict_types=1);

namespace Modules\Transactions\Reading;

use Modules\Forms\Pdf;
use Symfony\Component\Process\Process;

/**
 * Text of each page: read straight from the PDF where it has text, and by OCR (Tesseract, on our own servers:
 * no document images are sent to outside services) for scanned pages and photos.
 */
final class PageText
{
    /** A page with fewer characters than this is treated as a scan and read by OCR. */
    private const MIN_TEXT = 40;

    public const MAX_PAGES = 60;

    public const MAX_OCR_PAGES = 30;

    /**
     * @return array{pages: list<string>, methods: list<string>}|null null when the file type cannot be read
     */
    public static function read(string $bytes, string $mime): ?array
    {
        return match ($mime) {
            'application/pdf' => self::pdf($bytes),
            'image/jpeg', 'image/png' => self::image($bytes, $mime === 'image/png' ? 'png' : 'jpg'),
            default => null,
        };
    }

    /**
     * @return array{pages: list<string>, methods: list<string>}
     */
    private static function pdf(string $bytes): array
    {
        $file = Pdf::tempFile('read-', $bytes);
        try {
            $p = new Process(['pdftotext', '-layout', '-l', (string) self::MAX_PAGES, $file, '-']);
            $p->setTimeout(60);
            $p->run();
            $raw = explode("\f", $p->getOutput());
            if (end($raw) === '') {
                array_pop($raw);
            }
            $pages = [];
            $methods = [];
            $ocr = 0;
            foreach ($raw as $i => $text) {
                $text = self::tidy($text);
                if (mb_strlen($text) >= self::MIN_TEXT) {
                    $pages[] = $text;
                    $methods[] = 'text';

                    continue;
                }
                $read = $ocr < self::MAX_OCR_PAGES ? self::ocrPage($file, $i + 1) : '';
                $ocr++;
                $pages[] = $read;
                $methods[] = $read !== '' ? 'ocr' : 'blank';
            }

            return ['pages' => $pages, 'methods' => $methods];
        } finally {
            @unlink($file);
        }
    }

    /**
     * @return array{pages: list<string>, methods: list<string>}
     */
    private static function image(string $bytes, string $extension): array
    {
        $file = tempnam(sys_get_temp_dir(), 'img-');
        if ($file === false) {
            return ['pages' => [''], 'methods' => ['blank']];
        }
        $path = $file.'.'.$extension;
        rename($file, $path);
        file_put_contents($path, $bytes);
        try {
            $text = self::tesseract($path);

            return ['pages' => [$text], 'methods' => [$text !== '' ? 'ocr' : 'blank']];
        } finally {
            @unlink($path);
        }
    }

    private static function ocrPage(string $pdf, int $page): string
    {
        $base = substr($pdf, 0, -4).'-p'.$page;
        $p = new Process(['pdftoppm', '-r', '200', '-gray', '-png', '-f', (string) $page, '-l', (string) $page, '-singlefile', $pdf, $base]);
        $p->setTimeout(60);
        $p->run();
        $png = $base.'.png';
        if (! is_file($png)) {
            return '';
        }
        try {
            return self::tesseract($png);
        } finally {
            @unlink($png);
        }
    }

    private static function tesseract(string $image): string
    {
        $p = new Process(['tesseract', $image, 'stdout', '-l', 'eng', '--psm', '3']);
        $p->setTimeout(90);
        $p->run();

        return $p->isSuccessful() ? self::tidy($p->getOutput()) : '';
    }

    private static function tidy(string $text): string
    {
        $lines = array_map(fn (string $l): string => rtrim((string) preg_replace('/[ \t]{3,}/', '   ', $l)), explode("\n", str_replace("\r", '', $text)));

        return trim((string) preg_replace("/\n{3,}/", "\n\n", implode("\n", $lines)));
    }
}
